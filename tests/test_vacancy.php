<?php

declare(strict_types=1);

/**
 * Day-of availability (App\Service\VacancyService, App\Domain\VacancyLevel).
 *
 * The feature exists because the booking system cannot answer the question it
 * answers: walk-up tickets are paper, so confirmed_seats is wrong on the day.
 * Everything here therefore turns on reports that people typed in, and the
 * properties worth asserting are about which report wins and how old it is.
 *
 *   - the newest report for a key is the current one, and nothing else is
 *   - a session with no report of its own falls back to the booth's state,
 *     flagged as a different claim rather than passed off as its own
 *   - a report nobody has refreshed in ninety minutes says so, because a stale
 *     ◎ on a wall sends people to a booth that filled up an hour ago
 *
 * And one that is structural: this writes to vacancy_reports and nothing else.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\VacancyLevel;
use App\Repository\EventRepository;
use App\Repository\VacancyRepository;
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

fixture_cleanup();
Db::execute('DELETE FROM vacancy_reports WHERE reported_by = ?', ['test:vacancy']);

$events = new EventRepository();
$repo = new VacancyRepository();
$service = new VacancyService();

$DAY = '2035-08-08';
$ago = static fn (int $minutes): string => date('Y-m-d H:i:s', time() - $minutes * 60);

try {
    $companyA = fixture_create_company('vacA');
    $companyB = fixture_create_company('vacB');

    $tour = $events->create($companyA, 'A社 見学', null, 'A棟 受付', 0, true);
    $build = $events->create($companyA, 'A社 体験', null, null, 0, true);
    $other = $events->create($companyB, 'B社 説明', null, null, 0, true);

    $s1 = fixture_create_session($tour, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    $s2 = fixture_create_session($tour, $DAY . ' 13:00:00', $DAY . ' 14:00:00', 20);
    fixture_create_session($build, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    fixture_create_session($other, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    // Another day, to prove the date filter does something.
    fixture_create_session($tour, '2035-08-09 10:00:00', '2035-08-09 11:00:00', 20);

    // --- the marks -----------------------------------------------------------
    $assert(VacancyLevel::Open->mark() === '◎' && VacancyLevel::None->mark() === '✕',
        'the four marks carry the meaning without needing colour');
    $assert(VacancyLevel::reconcile(VacancyLevel::Open, 0) === VacancyLevel::None,
        'a remaining count of zero overrides ◎ - the two would contradict each other on screen');
    $assert(VacancyLevel::reconcile(VacancyLevel::Open, 3) === VacancyLevel::Open,
        'any other count is left to the operator; 3 tickets can be ◎ or △');
    $assert(VacancyLevel::reconcile(VacancyLevel::Few, null) === VacancyLevel::Few,
        'and a report with no count at all is untouched');

    // --- nothing reported yet ------------------------------------------------
    $rows = $service->forEvents($DAY, null, false);
    $assert(count($rows) === 3, 'every booth running that day is listed, reported or not');
    $assert($rows[0]['report'] === null,
        'with no report the entry is null - the page shows a dash, not an empty space');

    // --- newest wins ---------------------------------------------------------
    $repo->add($tour, null, 'open', null, null, 'test:vacancy', $ago(30));
    $repo->add($tour, null, 'few', 4, null, 'test:vacancy', $ago(5));

    $byId = [];
    foreach ($service->forEvents($DAY, null, false) as $row) {
        $byId[(int) $row['id']] = $row;
    }
    $assert($byId[$tour]['report']['level'] === VacancyLevel::Few,
        'the newest report is the current one');
    $assert($byId[$tour]['report']['remaining'] === 4, 'and brings its ticket count with it');
    $assert($byId[$build]['report'] === null, 'a report never leaks to another booth');

    // --- age -----------------------------------------------------------------
    $assert($byId[$tour]['report']['is_stale'] === false, 'five minutes old is current');

    $repo->add($other, null, 'ample', null, null, 'test:vacancy', $ago(VacancyService::STALE_MINUTES + 1));
    foreach ($service->forEvents($DAY, null, false) as $row) {
        $byId[(int) $row['id']] = $row;
    }
    $assert($byId[$other]['report']['is_stale'] === true,
        'past ninety minutes it is marked stale - the dangerous failure is a stale ◎ on a wall');
    $assert($byId[$other]['report']['age_minutes'] >= VacancyService::STALE_MINUTES,
        'and the age is carried so the screen can say how long');

    // --- per session, and the fallback --------------------------------------
    $repo->add($tour, $s1, 'none', 0, null, 'test:vacancy', $ago(10));

    $sessions = [];
    foreach ($service->forSessions($DAY, null, false, null, false) as $row) {
        $sessions[(int) $row['id']] = $row;
    }

    $assert($sessions[$s1]['report']['level'] === VacancyLevel::None,
        'a session shows its own report when it has one');
    $assert($sessions[$s1]['fallback'] === null,
        'and then has no need of the fallback');
    $assert($sessions[$s2]['report'] === null && $sessions[$s2]['fallback'] !== null,
        'a session with no report of its own falls back to the booth');
    $assert($sessions[$s2]['fallback']['level'] === VacancyLevel::Few,
        'showing what the booth last said, which is a different claim and is labelled as one');

    // --- the date filter -----------------------------------------------------
    $assert($service->forEvents('2035-08-09', null, false) !== [],
        'the next day lists its own session');
    $assert($service->forEvents('2035-08-10', null, false) === [],
        'and a day with nothing on returns nothing');

    // --- company scope -------------------------------------------------------
    $scoped = $service->forEvents($DAY, $companyA, false);
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $scoped);
    $assert(in_array($tour, $ids, true) && !in_array($other, $ids, true),
        "a company account sees only its own booths");

    // --- finished sessions are dropped from the public view ------------------
    $past = fixture_create_session($build, date('Y-m-d') . ' 00:00:00', date('Y-m-d') . ' 00:30:00', 20);
    $today = date('Y-m-d');
    $upcomingIds = array_map(
        static fn (array $r): int => (int) $r['id'],
        $service->forSessions($today, null, true, null, false)
    );
    $allIds = array_map(
        static fn (array $r): int => (int) $r['id'],
        $service->forSessions($today, null, false, null, false)
    );
    $assert(!in_array($past, $upcomingIds, true),
        'a session that already finished is not shown to visitors');
    $assert(in_array($past, $allIds, true),
        'but the office can still ask for the whole day');

    // --- what it writes to ----------------------------------------------------
    // The claim the whole feature rests on: the booking tables are read only.
    $seatsBefore = (int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$s1]);
    $bookingsBefore = (int) Db::scalar('SELECT COUNT(*) FROM bookings');
    $repo->add($tour, $s2, 'ample', 9, null, 'test:vacancy');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$s1]) === $seatsBefore,
        'writing a report does not touch confirmed_seats');
    $assert((int) Db::scalar('SELECT COUNT(*) FROM bookings') === $bookingsBefore,
        'nor bookings - this feature cannot disturb the booking system');

    // --- a booth with no sessions at all --------------------------------------
    // The design always said these take a "current status" and nothing else.
    // An inner join on event_sessions quietly dropped them, so the one kind of
    // event that can ONLY be reported this way could not be reported at all.
    $walkIn = $events->create($companyA, 'A社 随時受付の展示', null, 'ロビー', 0, true);

    $ids = array_map(
        static fn (array $r): int => (int) $r['id'],
        $service->forEvents($DAY, $companyA, false)
    );
    $assert(in_array($walkIn, $ids, true),
        'a booth with no sessions is listed, because a current status is all it can have');

    $counts = [];
    foreach ($service->forEvents($DAY, $companyA, false) as $row) {
        $counts[(int) $row['id']] = (int) $row['session_count'];
    }
    $assert($counts[$walkIn] === 0 && $counts[$tour] === 2,
        'and session_count tells the screen which rows have a per-session form');

    // An event whose sessions are all on other days is a different case: it is
    // not running today, so there is nothing to say about it today.
    $otherDay = $events->create($companyA, 'A社 来月だけ', null, null, 0, true);
    fixture_create_session($otherDay, '2035-12-01 10:00:00', '2035-12-01 11:00:00', 10);
    $ids = array_map(
        static fn (array $r): int => (int) $r['id'],
        $service->forEvents($DAY, $companyA, false)
    );
    $assert(!in_array($otherDay, $ids, true),
        'a booth running only on other days stays off this day');

    $repo->add($walkIn, null, 'open', null, null, 'test:vacancy');
    $byId = [];
    foreach ($service->forEvents($DAY, $companyA, false) as $row) {
        $byId[(int) $row['id']] = $row;
    }
    $assert($byId[$walkIn]['report'] !== null
        && $byId[$walkIn]['report']['level'] === VacancyLevel::Open,
        'and its current status is published like any other');

    // --- the signage rehearsal -------------------------------------------------
    // Checking the wall display must not require the day it has to be right on.
    $before = (int) Db::scalar('SELECT COUNT(*) FROM vacancy_reports');
    $sample = $service->sampleRows(8);
    $assert(count($sample) === 8, 'the rehearsal builds as many rows as asked for');
    $assert((int) Db::scalar('SELECT COUNT(*) FROM vacancy_reports') === $before,
        'and writes none of them - a rehearsal must not become data');

    $marks = [];
    $stale = 0;
    foreach ($sample as $row) {
        $marks[$row['report']['level']->value] = true;
        $stale += $row['report']['is_stale'] ? 1 : 0;
    }
    $assert(count($marks) === 4, 'all four marks appear, so every colour can be judged');
    $assert($stale > 0, 'and one row is old enough to grey out, which is the case you cannot stage');

    // --- the history the office reads back ------------------------------------
    $recent = $repo->recent(5, $companyA);
    $assert($recent !== [] && (string) $recent[0]['reported_by'] === 'test:vacancy',
        'who entered what is kept, because the day runs on relayed messages');
} finally {
    Db::execute('DELETE FROM vacancy_reports WHERE reported_by = ?', ['test:vacancy']);
    fixture_cleanup();
}

echo $failures === 0 ? "vacancy: all OK\n" : "vacancy: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
