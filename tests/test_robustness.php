<?php

declare(strict_types=1);

/**
 * Guards for three refactorings that are easy to regress silently:
 *
 *   1. confirmed_at is derived from status inside BookingRepository::insert(),
 *      so the pair can never disagree (invariant (5) in design.md E-4).
 *   2. Enum reads on database values use tryFrom and fail closed, so an
 *      unrecognised ENUM value is refused rather than raising \ValueError.
 *   3. Duplicate-key errors are identified by errno plus the index name from
 *      errorInfo, not by matching words in a localisable message.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingStatus;
use App\Domain\SessionStatus;
use App\Exception\DuplicateBookingException;
use App\Exception\ValidationException;
use App\Repository\BookingRepository;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\TokenService;

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

/** Build a PDOException shaped like the driver's, for the message-parsing tests. */
$duplicateError = static function (string $driverMessage): PDOException {
    $e = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 ' . $driverMessage);
    $e->errorInfo = ['23000', 1062, $driverMessage];
    return $e;
};

fixture_cleanup();
$company = fixture_create_company('robust');
$event   = fixture_create_event($company, 'robustness');

try {
    // --- 1. confirmed_at follows status ------------------------------------
    $session = fixture_create_session($event, '2026-12-10 10:00:00', '2026-12-10 11:00:00', 1);
    $service = new BookingService();

    $confirmed = $service->book($session, fixture_email('rb-a'), 'A', 1);
    $waitlisted = $service->book($session, fixture_email('rb-b'), 'B', 1);

    $rowA = Db::selectOne('SELECT status, confirmed_at FROM bookings WHERE id = ?', [$confirmed['booking_id']]);
    $rowB = Db::selectOne('SELECT status, confirmed_at FROM bookings WHERE id = ?', [$waitlisted['booking_id']]);

    $assert($rowA['status'] === 'confirmed' && $rowA['confirmed_at'] !== null,
        'confirmed booking gets confirmed_at');
    $assert($rowB['status'] === 'waitlisted' && $rowB['confirmed_at'] === null,
        'waitlisted booking leaves confirmed_at null');

    // The repository owns the pairing now, so even a direct call cannot
    // produce the combination that breaks invariant (5).
    $repo = new BookingRepository();
    $direct = $repo->insert(
        referenceCode:   TokenService::newReferenceCode(),
        sessionId:       $session,
        applicantId:     (int) Db::scalar('SELECT applicant_id FROM bookings WHERE id = ?', [$waitlisted['booking_id']]),
        email:           fixture_email('rb-b'),
        name:            'B',
        partySize:       1,
        status:          BookingStatus::Cancelled,
        waitlistSeq:     null,
        cancelTokenHash: TokenService::hashToken('direct-insert-probe'),
    );
    $rowC = Db::selectOne('SELECT status, confirmed_at FROM bookings WHERE id = ?', [$direct]);
    $assert($rowC['status'] === 'cancelled' && $rowC['confirmed_at'] === null,
        'a non-confirmed insert cannot carry confirmed_at');

    // --- 2. enum reads fail closed ------------------------------------------
    $assert(SessionStatus::tryFrom('open') === SessionStatus::Open, 'known session status parses');
    $assert(SessionStatus::tryFrom('archived') === null, 'unknown session status yields null, not an error');
    $assert(BookingStatus::tryFrom('promoted') === null, 'unknown booking status yields null, not an error');

    // The guard is `tryFrom(...) !== Open`, so the two halves of "an unknown
    // status fails closed" are checked separately: tryFrom returns null rather
    // than throwing (above), and anything that is not Open is refused (below).
    // Widening the ENUM to stage a genuinely unknown value would need DDL, and
    // the application account deliberately has none.
    $probe = fixture_create_session($event, '2026-12-11 10:00:00', '2026-12-11 11:00:00', 5);
    Db::execute("UPDATE event_sessions SET status = 'closed' WHERE id = ?", [$probe]);

    $refused = false;
    try {
        $service->book($probe, fixture_email('rb-c'), 'C', 1);
    } catch (ValidationException) {
        $refused = true;
    }
    $assert($refused, 'a session that is not open is refused, not sold');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$probe]) === 0,
        'no seats were taken on the refused session');

    // --- 3. duplicate-key identification -------------------------------------
    // MariaDB / MySQL 5.7 phrasing, MySQL 8 phrasing (table-qualified),
    // and a localised sentence with the same quoted tokens.
    $mariadb = $duplicateError("Duplicate entry '12:34' for key 'uq_bookings_active'");
    $mysql8  = $duplicateError("Duplicate entry '12:34' for key 'bookings.uq_bookings_active'");
    $jp      = $duplicateError("キー 'bookings.uq_bookings_active' に対して重複エントリー '12:34' です");

    $assert(Db::isDuplicateKeyFor($mariadb, 'uq_bookings_active'), 'MariaDB message matched');
    $assert(Db::isDuplicateKeyFor($mysql8, 'uq_bookings_active'), 'MySQL 8 table-qualified message matched');
    $assert(Db::isDuplicateKeyFor($jp, 'uq_bookings_active'), 'localised message matched');

    // A different index on the same table must not be mistaken for it.
    $otherKey = $duplicateError("Duplicate entry 'abc' for key 'bookings.uq_bookings_ref'");
    $assert(!Db::isDuplicateKeyFor($otherKey, 'uq_bookings_active'), 'a different index does not match');

    // A non-1062 error is rejected before the message is even considered.
    $deadlock = new PDOException('SQLSTATE[40001]: deadlock');
    $deadlock->errorInfo = ['40001', 1213, "Deadlock found; mentions uq_bookings_active"];
    $assert(!Db::isDuplicateKeyFor($deadlock, 'uq_bookings_active'), 'a non-duplicate error never matches');

    // End to end: the unique index really does surface as the friendly error.
    // Booking the same session twice is normally caught by the overlap check;
    // this proves the constraint-level backstop translates too.
    $sameSession = false;
    try {
        $repo->insert(
            referenceCode:   TokenService::newReferenceCode(),
            sessionId:       $session,
            applicantId:     (int) Db::scalar('SELECT applicant_id FROM bookings WHERE id = ?', [$confirmed['booking_id']]),
            email:           fixture_email('rb-a'),
            name:            'A',
            partySize:       1,
            status:          BookingStatus::Confirmed,
            waitlistSeq:     null,
            cancelTokenHash: TokenService::hashToken('backstop-probe'),
        );
    } catch (PDOException $e) {
        $sameSession = Db::isDuplicateKeyFor($e, 'uq_bookings_active');
    }
    $assert($sameSession, 'a real uq_bookings_active violation is identified on this server');

    // And that BookingService turns it into the user-facing exception.
    $translated = false;
    try {
        $service->book($session, fixture_email('rb-a'), 'A', 1);
    } catch (DuplicateBookingException) {
        $translated = true;
    }
    $assert($translated, 'BookingService reports a repeat application as a duplicate');

    // --- 4. cancellation: lock order and seat-counter safety -----------------
    // The fixed order (applicants -> event_sessions -> bookings) is what keeps
    // deadlocks impossible; that it holds under real contention is scenario 6
    // in tests/test_concurrency.php. Here: the counter arithmetic around it.
    $cancelSession = fixture_create_session($event, '2026-12-12 10:00:00', '2026-12-12 11:00:00', 4);
    $held = $service->book($cancelSession, fixture_email('rb-cancel'), 'Cancel', 3);
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$cancelSession]) === 3,
        'three seats held before cancelling');

    $cancels = new CancellationService();
    $out = $cancels->cancelById((int) $held['booking_id'], 'test:robustness');
    $assert($out['already_cancelled'] === false && $out['was'] === BookingStatus::Confirmed,
        'cancel reports the previous status');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$cancelSession]) === 0,
        'exactly party_size seats returned');

    $row = Db::selectOne(
        'SELECT status, cancelled_at, waitlist_seq FROM bookings WHERE id = ?',
        [$held['booking_id']]
    );
    $assert($row['status'] === 'cancelled' && $row['cancelled_at'] !== null && $row['waitlist_seq'] === null,
        'cancelled row is stamped and its queue number cleared');

    $audit = Db::selectOne(
        'SELECT from_status, to_status, actor FROM booking_events
         WHERE booking_id = ? ORDER BY id DESC LIMIT 1',
        [$held['booking_id']]
    );
    $assert($audit['from_status'] === 'confirmed' && $audit['to_status'] === 'cancelled'
        && $audit['actor'] === 'test:robustness', 'audit row records the transition and the actor');

    $mailed = (int) Db::scalar(
        "SELECT COUNT(*) FROM mail_queue WHERE booking_id = ? AND subject LIKE '%キャンセル%'",
        [$held['booking_id']]
    );
    $assert($mailed === 1, 'cancellation mail queued in the outbox');

    // Idempotent: a second cancel neither errors nor gives the seats back twice.
    $again = $cancels->cancelById((int) $held['booking_id'], 'test:robustness');
    $assert($again['already_cancelled'] === true, 'a second cancel is a no-op');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$cancelSession]) === 0,
        'seats not returned twice');

    // Drifted counter: the guarded decrement must refuse instead of going
    // below zero. Staged by writing the counter directly, which is exactly the
    // kind of manual edit that causes drift in the first place.
    $drift = fixture_create_session($event, '2026-12-13 10:00:00', '2026-12-13 11:00:00', 5);
    $driftBooking = $service->book($drift, fixture_email('rb-drift'), 'Drift', 3);
    Db::execute('UPDATE event_sessions SET confirmed_seats = 1 WHERE id = ?', [$drift]);

    $refusedDrift = false;
    try {
        $cancels->cancelById((int) $driftBooking['booking_id'], 'test:robustness');
    } catch (RuntimeException $e) {
        $refusedDrift = str_contains($e->getMessage(), 'Seat counter drift');
    }
    $assert($refusedDrift, 'a drifted counter is reported, not decremented below zero');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$drift]) === 1,
        'the drifted counter was left untouched');
    $assert(Db::scalar('SELECT status FROM bookings WHERE id = ?', [$driftBooking['booking_id']]) === 'confirmed',
        'the refused cancellation rolled back entirely');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "robustness: all OK\n" : "robustness: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
