<?php

declare(strict_types=1);

/**
 * Serial capacity semantics on scratch data: seats consumed by party size,
 * waitlist fallback when full, SessionFullException when the caller declines
 * the waitlist, seats returned on cancel, and the database CHECK backstop
 * that stops overselling even if the application logic were wrong.
 *
 * (The concurrent versions of these properties are tests/test_concurrency.php.)
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\SessionFullException;
use App\Service\BookingService;
use App\Service\CancellationService;

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
$seats = static fn (int $id): int => (int) Db::scalar(
    'SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$id]
);

fixture_cleanup(); // leftovers from a crashed earlier run

$company = fixture_create_company('capacity');
$event   = fixture_create_event($company, 'capacity test');
$session = fixture_create_session($event, '2026-11-01 10:00:00', '2026-11-01 11:00:00', 2);

$service = new BookingService();

try {
    // Party size consumes seats, not bookings: one party of 2 fills capacity 2.
    $a = $service->book($session, fixture_email('cap-a'), 'A', 2);
    $assert($a['status'] === BookingStatus::Confirmed, 'party of 2 confirmed');
    $assert($seats($session) === 2, 'confirmed_seats = 2 after one booking');

    // Full: the default path falls back to the waitlist...
    $b = $service->book($session, fixture_email('cap-b'), 'B', 1);
    $assert($b['status'] === BookingStatus::Waitlisted, 'next booking waitlisted');
    $assert($b['waitlist_seq'] === 1, 'waitlist_seq starts at 1');

    // ...and refuses outright only when the caller declined the waitlist.
    try {
        $service->book($session, fixture_email('cap-c'), 'C', 1, allowWaitlist: false);
        $assert(false, 'no-waitlist booking on a full session throws');
    } catch (SessionFullException $e) {
        $assert($e->remaining === 0, 'no-waitlist booking on a full session throws');
    }

    // Cancel returns exactly party_size seats.
    (new CancellationService())->cancelById((int) $a['booking_id'], 'test:capacity');
    $assert($seats($session) === 0, 'cancel returns both seats');

    // The CHECK constraint is the last line of defence against overselling -
    // and Db::isCheckViolation must recognise the server's error code.
    try {
        Db::execute('UPDATE event_sessions SET confirmed_seats = 3 WHERE id = ?', [$session]);
        $assert(false, 'CHECK rejects confirmed_seats > capacity');
    } catch (PDOException $e) {
        $assert(Db::isCheckViolation($e), 'CHECK rejects confirmed_seats > capacity');
    }
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "capacity: all OK\n" : "capacity: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
