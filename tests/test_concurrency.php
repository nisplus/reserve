<?php

declare(strict_types=1);

/**
 * The eight concurrency scenarios from docs/design.md E-3, run for real.
 *
 * Parallel cases spawn bin/concurrency_test.php workers (barrier-synchronised
 * CLI processes, because `php -S` serialises requests on Windows and can never
 * produce a race). Serial cases call the services directly. Everything runs
 * on scratch fixture data and is torn down afterwards; the E-4 invariants are
 * checked at the end over the whole database.
 *
 * Usage: php tests/test_concurrency.php   (takes ~1 minute)
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingStatus;
use App\Service\BookingService;
use App\Service\CancellationService;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$failures = 0;
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? '  OK  ' : '  NG  ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

/**
 * Run one PHP script with arguments; returns [exitCode, stdout].
 * proc_open with an array argv: PHP quotes each element itself, which is the
 * only reliable way to pass arguments on Windows without cmd.exe mangling.
 *
 * @param array<int, string> $args
 * @return array{0: int, 1: string}
 */
function run_php(array $args): array
{
    $process = proc_open(
        array_merge([PHP_BINARY], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return [1, ''];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code, $stdout . ($stderr !== '' ? $stderr : '')];
}

/**
 * Launch one worker per argument set, all firing at the same barrier instant,
 * and return the trimmed one-line outputs.
 *
 * @param array<int, array<int, string>> $argSets
 * @return array<int, string>
 */
function run_workers(array $argSets): array
{
    $script = dirname(__DIR__) . '/bin/concurrency_test.php';
    // Enough headroom for every process to boot and connect before the barrier.
    $startAt = sprintf('%.3F', microtime(true) + max(3.0, 1.5 + 0.15 * count($argSets)));

    $procs = [];
    foreach ($argSets as $index => $args) {
        $process = proc_open(
            array_merge([PHP_BINARY, $script], $args, ['--start-at=' . $startAt]),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $procs[$index] = [$process, $pipes];
    }

    $outputs = [];
    foreach ($procs as $index => [$process, $pipes]) {
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $outputs[$index] = trim($stdout !== '' ? $stdout : $stderr);
    }
    return $outputs;
}

/** @param array<int, string> $outputs */
function count_prefix(array $outputs, string $prefix): int
{
    return count(array_filter($outputs, static fn (string $o): bool => str_starts_with($o, $prefix)));
}

$seats = static fn (int $id): int => (int) Db::scalar(
    'SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$id]
);

// ---------------------------------------------------------------------------
fixture_cleanup();
$company = fixture_create_company('concurrency');
$eventId = fixture_create_event($company, 'concurrency scenarios');

try {
    // --- 1: capacity 10, 20 strangers, party=1 -----------------------------
    echo "[1] 20 workers / capacity 10 / party 1\n";
    $s1 = fixture_create_session($eventId, '2026-11-01 10:00:00', '2026-11-01 11:00:00', 10);
    $sets = [];
    for ($i = 1; $i <= 20; $i++) {
        $sets[] = ['--action=book', '--session=' . $s1, '--party=1', '--email=' . fixture_email("s1-{$i}")];
    }
    $out = run_workers($sets);
    $assert(count_prefix($out, 'CONFIRMED') === 10, 'exactly 10 confirmed (got ' . count_prefix($out, 'CONFIRMED') . ')');
    $assert(count_prefix($out, 'WAITLISTED') === 10, 'exactly 10 waitlisted');
    $assert($seats($s1) === 10, 'confirmed_seats = 10, not oversold');

    // --- 2: capacity 10, 20 strangers, party=3 -----------------------------
    echo "[2] 20 workers / capacity 10 / party 3\n";
    $s2 = fixture_create_session($eventId, '2026-11-02 10:00:00', '2026-11-02 11:00:00', 10);
    $sets = [];
    for ($i = 1; $i <= 20; $i++) {
        $sets[] = ['--action=book', '--session=' . $s2, '--party=3', '--email=' . fixture_email("s2-{$i}")];
    }
    $out = run_workers($sets);
    $assert(count_prefix($out, 'CONFIRMED') === 3, 'exactly 3 confirmed = 9 seats (got ' . count_prefix($out, 'CONFIRMED') . ')');
    $assert(count_prefix($out, 'WAITLISTED') === 17, 'the other 17 waitlisted');
    $assert($seats($s2) === 9 && $seats($s2) <= 10, 'confirmed_seats = 9, within capacity');

    // --- 3: one person, 10 overlapping sessions of different events --------
    echo "[3] same e-mail / 10 overlapping sessions across events\n";
    $sets = [];
    for ($i = 1; $i <= 10; $i++) {
        $otherEvent = fixture_create_event($company, "overlap target {$i}");
        $sid = fixture_create_session($otherEvent, '2026-11-03 10:00:00', '2026-11-03 11:00:00', 10);
        $sets[] = ['--action=book', '--session=' . $sid, '--party=1', '--email=' . fixture_email('s3-same')];
    }
    $out = run_workers($sets);
    $assert(count_prefix($out, 'CONFIRMED') === 1, 'exactly 1 success (got ' . count_prefix($out, 'CONFIRMED') . ')');
    $assert(count_prefix($out, 'DUPLICATE') === 9, '9 rejected as overlapping');

    // --- 4: one person, same session, 10 in parallel ------------------------
    echo "[4] same e-mail / same session x 10\n";
    $s4 = fixture_create_session($eventId, '2026-11-04 10:00:00', '2026-11-04 11:00:00', 10);
    $sets = array_fill(0, 10, ['--action=book', '--session=' . $s4, '--party=1', '--email=' . fixture_email('s4-same')]);
    $out = run_workers($sets);
    $assert(count_prefix($out, 'CONFIRMED') === 1, 'exactly 1 success');
    $assert(count_prefix($out, 'DUPLICATE') === 9, '9 rejected as duplicates');

    // --- 5: confirm -> cancel -> re-apply (serial; the active_key trick) ----
    echo "[5] cancel then re-apply, same session\n";
    $s5 = fixture_create_session($eventId, '2026-11-05 10:00:00', '2026-11-05 11:00:00', 2);
    $service = new BookingService();
    $first = $service->book($s5, fixture_email('s5'), 'S5', 1);
    (new CancellationService())->cancelById((int) $first['booking_id'], 'test:concurrency');
    $second = $service->book($s5, fixture_email('s5'), 'S5', 1);
    $assert($second['status'] === BookingStatus::Confirmed, 're-application accepted after cancel');

    // --- 6: double-cancel in parallel ---------------------------------------
    echo "[6] 2 parallel cancels of one booking\n";
    $s6 = fixture_create_session($eventId, '2026-11-06 10:00:00', '2026-11-06 11:00:00', 2);
    $b6 = $service->book($s6, fixture_email('s6'), 'S6', 2);
    $out = run_workers(array_fill(0, 2, ['--action=cancel', '--booking=' . $b6['booking_id']]));
    $assert(count_prefix($out, 'CANCELLED') === 1 && count_prefix($out, 'ALREADY') === 1,
        'one cancel wins, one sees already-cancelled');
    $assert($seats($s6) === 0, 'seats decremented exactly once (no unsigned wrap)');

    // --- 7: back-to-back slots, same person, in parallel --------------------
    echo "[7] adjacent slots booked in parallel by one person\n";
    $s7a = fixture_create_session($eventId, '2026-11-07 10:00:00', '2026-11-07 10:45:00', 5);
    $s7b = fixture_create_session($eventId, '2026-11-07 10:45:00', '2026-11-07 11:30:00', 5);
    $out = run_workers([
        ['--action=book', '--session=' . $s7a, '--party=1', '--email=' . fixture_email('s7')],
        ['--action=book', '--session=' . $s7b, '--party=1', '--email=' . fixture_email('s7')],
    ]);
    $assert(count_prefix($out, 'CONFIRMED') === 2, 'both adjacent slots accepted (half-open boundary)');

    // --- 8: cancel frees a seat, two parallel promotions of the same booking -
    echo "[8] 2 parallel promotions after a cancellation\n";
    $s8 = fixture_create_session($eventId, '2026-11-08 10:00:00', '2026-11-08 11:00:00', 1);
    $a8 = $service->book($s8, fixture_email('s8-a'), 'S8A', 1);          // fills the session
    $b8 = $service->book($s8, fixture_email('s8-b'), 'S8B', 1);          // waitlisted
    (new CancellationService())->cancelById((int) $a8['booking_id'], 'test:concurrency');
    $out = run_workers(array_fill(0, 2, ['--action=promote', '--booking=' . $b8['booking_id']]));
    $assert(count_prefix($out, 'PROMOTED') === 1 && count_prefix($out, 'REJECTED') === 1,
        'exactly one promotion succeeds');
    $assert($seats($s8) === 1, 'confirmed_seats = 1 <= capacity (no double promotion)');
    $b8status = Db::selectOne('SELECT status, waitlist_seq FROM bookings WHERE id = ?', [(int) $b8['booking_id']]);
    $assert($b8status['status'] === 'confirmed' && $b8status['waitlist_seq'] === null,
        'promoted booking is confirmed with waitlist_seq cleared');

    // --- E-4 invariants over the whole database ------------------------------
    echo "[E-4] invariants\n";
    [$code, $output] = run_php([__DIR__ . '/test_invariants.php']);
    echo preg_replace('/^/m', '  ', trim($output)), "\n";
    $assert($code === 0, 'all five invariants clean');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "\nconcurrency: ALL 8 SCENARIOS GREEN\n" : "\nconcurrency: {$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
