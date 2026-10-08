<?php

declare(strict_types=1);

/**
 * The availability input screen, driven the way a browser drives it.
 *
 * test_vacancy.php covers the service and the repository, and both of the
 * faults that reached the office got past it anyway, because neither lived
 * there: one was a redirect that threw away the scroll position, and one was
 * a variable left out of a closure's use list, which no amount of testing
 * VacancyService would have caught. `php -l` does not see it either - the
 * code is syntactically perfect and dies the moment it runs.
 *
 * So this posts the actual forms at the actual front controller, through
 * bin/request.php, and looks at what came back. Slower than calling a method,
 * and it covers the parts that only exist once a request is real: the CSRF
 * check, the sign-in, the transaction, the redirect target.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\VacancyLevel;
use App\Repository\EventRepository;
use App\Service\VacancyService;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$failures = 0;
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'OK  ' : 'NG  ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

/**
 * One request at the front controller, in a process of its own.
 *
 * A separate process per request on purpose: public/index.php is a script,
 * not a function, and `require` would only run it the first time.
 *
 * @param array<string, string> $post
 * @return array{status: int, location: string, body: string}
 */
$request = static function (string $path, array $post = []): array {
    $args = [escapeshellarg($path), '--headers'];
    foreach ($post as $key => $value) {
        $args[] = '--post=' . escapeshellarg($key . '=' . $value);
    }

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/bin/request.php') . ' '
        . implode(' ', $args) . ' 2>&1';

    $output = (string) shell_exec($command);

    preg_match('/^HTTP (\d+)/m', $output, $status);
    preg_match('/^Location:\s*(\S+)/mi', $output, $location);

    return [
        'status' => (int) ($status[1] ?? 0),
        'location' => $location[1] ?? '',
        'body' => $output,
    ];
};

$events = new EventRepository();
$DAY = date('Y-m-d');
$AHEAD = date('Y-m-d', strtotime('+21 days'));

fixture_cleanup();
Db::execute('DELETE FROM vacancy_reports WHERE reported_by LIKE ?', ['%CT-TEST-%']);
Db::execute('DELETE FROM admin_users WHERE username LIKE ?', [FIXTURE_PREFIX . '%']);

$adminId = null;

try {
    $company = fixture_create_company('vacadmin');
    // 予約不要, because that is all this screen carries.
    $event = $events->create($company, 'CT-TEST- 工作室', null, '工房', 0, true, false);
    $s1 = fixture_create_session($event, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 0);
    $s2 = fixture_create_session($event, $DAY . ' 13:00:00', $DAY . ' 14:00:00', 0);

    Db::execute(
        "INSERT INTO admin_users (username, password_hash, display_name, role, is_active)
         VALUES (?, ?, ?, 'superadmin', 1)",
        [FIXTURE_PREFIX . 'vacadmin', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'CT-TEST- 事務局']
    );
    $adminId = Db::lastInsertId();

    /*
     * Sign in by writing the session bin/request.php reuses, rather than by
     * posting the login form: this suite is about the availability screens,
     * and a password round-trip here would only make it fail for a second
     * reason. Auth still reads the account from the database on every
     * request, so the sign-in itself is real.
     */
    session_id('clitestsession');
    App\Core\SessionManager::start();
    App\Core\SessionManager::set('_admin_id', $adminId);
    App\Core\SessionManager::set('_admin_login_at', time());
    App\Core\SessionManager::set('_admin_seen_at', time());
    session_write_close();

    $reports = static fn (?int $sessionId): array => Db::select(
        $sessionId === null
            ? 'SELECT * FROM vacancy_reports WHERE event_id = ? AND session_id IS NULL ORDER BY id'
            : 'SELECT * FROM vacancy_reports WHERE event_id = ? AND session_id = ? ORDER BY id',
        $sessionId === null ? [$event] : [$event, $sessionId]
    );

    // --- one mark, from the quick screen ---------------------------------------
    $response = $request('/admin/vacancy', [
        'date' => $DAY,
        'event_id' => (string) $event,
        'level' => 'open',
    ]);
    $assert($response['status'] === 303, 'pressing a mark redirects rather than re-rendering');
    $assert(str_contains($response['location'], '#e' . $event),
        'and lands back on the row that was pressed, not the top of a long table');

    $rows = $reports(null);
    $assert(count($rows) === 1 && (string) $rows[0]['level'] === 'open',
        'the mark is saved');

    // --- the day on screen is the day it is about -------------------------------
    // The fault the office hit: a mark entered while looking at another day
    // was stamped with the wall clock, and then could not be read back.
    $response = $request('/admin/vacancy', [
        'date' => $AHEAD,
        'event_id' => (string) $event,
        'level' => 'few',
    ]);
    $assert($response['status'] === 303, 'a mark for another day saves the same way');

    $rows = $reports(null);
    $assert(count($rows) === 2, 'and is a second report, because reports are only ever appended');
    $assert(str_starts_with((string) $rows[1]['reported_at'], $AHEAD),
        'stamped with the day on screen, or it would be written somewhere nothing reads');

    $service = new VacancyService();
    $ahead = [];
    foreach ($service->forEvents($AHEAD, $company, false) as $row) {
        $ahead[(int) $row['id']] = $row;
    }
    $assert(($ahead[$event]['report']['level'] ?? null) === VacancyLevel::Few,
        'and it comes straight back on that day - the whole point, and what used to fail');

    $today = [];
    foreach ($service->forEvents($DAY, $company, false) as $row) {
        $today[(int) $row['id']] = $row;
    }
    $assert(($today[$event]['report']['level'] ?? null) === VacancyLevel::Open,
        "while today still shows today's, so a dry run cannot leak into the day");

    // --- a whole programme's rounds at once -------------------------------------
    // This is the request that died on a variable missing from a closure's
    // use list. Nothing short of sending it would have found that.
    $response = $request('/admin/vacancy/sessions', [
        'date' => $DAY,
        'event_id' => (string) $event,
        'level[' . $s1 . ']' => 'none',
        'remaining[' . $s1 . ']' => '0',
        'level[' . $s2 . ']' => 'ample',
        'remaining[' . $s2 . ']' => '12',
    ]);
    $assert($response['status'] === 303,
        'saving the rounds together returns a redirect, not a stack trace');
    $assert(!str_contains($response['body'], 'Undefined') && !str_contains($response['body'], 'Fatal'),
        'and raises nothing on the way - the kind of fault php -l cannot see');
    $assert(str_contains($response['location'], '#sessions'),
        'landing back on the panel, where the result of the save is visible');

    $first = $reports($s1);
    $second = $reports($s2);
    $assert(count($first) === 1 && (string) $first[0]['level'] === 'none' && (int) $first[0]['remaining'] === 0,
        'both rounds are saved');
    $assert(count($second) === 1 && (string) $second[0]['level'] === 'ample' && (int) $second[0]['remaining'] === 12,
        'each with its own mark and its own ticket count');
    $assert(str_starts_with((string) $first[0]['reported_at'], $DAY),
        'stamped with the day on screen, the same as a single mark');

    // --- rows left blank are not guesses ----------------------------------------
    $response = $request('/admin/vacancy/sessions', [
        'date' => $DAY,
        'event_id' => (string) $event,
        'level[' . $s1 . ']' => '',
        'level[' . $s2 . ']' => '',
    ]);
    $assert($response['status'] === 303 && count($reports($s1)) === 1,
        'a form with nothing chosen writes nothing - a round missing from the photo stays unreported');

    // --- what the screen refuses --------------------------------------------------
    $response = $request('/admin/vacancy', [
        'date' => $DAY,
        'event_id' => (string) $event,
        'level' => 'banana',
    ]);
    $assert(count($reports(null)) === 2,
        'a mark this build does not know is refused rather than stored');

    $booked = $events->create($company, 'CT-TEST- 要予約', null, null, 0, true, true);
    $response = $request('/admin/vacancy', [
        'date' => $DAY,
        'event_id' => (string) $booked,
        'level' => 'open',
    ]);
    $assert($response['status'] === 404,
        'and a programme this board does not carry is refused, not saved where nothing shows it');
} finally {
    Db::execute('DELETE FROM vacancy_reports WHERE event_id IN (SELECT id FROM events WHERE title LIKE ?)', ['CT-TEST-%']);
    if ($adminId !== null) {
        Db::execute('DELETE FROM admin_users WHERE id = ?', [$adminId]);
    }
    fixture_cleanup();

    /*
     * Sign out through the application, in a process of its own: this one
     * has already printed, so it can no longer open a session to clear.
     * Leaving a signed-in session behind would hand the next suite an
     * administrator it never asked for.
     */
    $request('/admin/logout', ['_' => '1']);
}

echo $failures === 0 ? "vacancy admin: all OK\n" : "vacancy admin: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
