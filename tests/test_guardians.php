<?php

declare(strict_types=1);

/**
 * Escorts who are not taking part (events.party_includes_guardians = 0).
 *
 * Two kinds of event share one 参加人数 field: a factory tour where everyone
 * walking round is a participant, and a workshop where the children make
 * something and their parents watch. The second needs the escorts recorded
 * without charging them against the capacity, which is the whole point of
 * bookings.guardian_count - so what these assertions are really checking is
 * that the seat accounting stayed on party_size alone.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\SessionFullException;
use App\Exception\ValidationException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
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

$seats = static fn (int $sessionId): int => (int) Db::scalar(
    'SELECT confirmed_seats FROM event_sessions WHERE id = ?',
    [$sessionId]
);

fixture_cleanup();
$company = fixture_create_company('guardians');
$events  = new EventRepository();
$service = new BookingService();

try {
    // --- the flag round-trips ------------------------------------------------
    $separate = $events->create($company, 'workshop', null, null, 0, true);
    $together = $events->create($company, 'factory tour', null, null, 0, true,
        partyIncludesGuardians: true);

    $assert((int) $events->find($separate)['party_includes_guardians'] === 0,
        'events default to counting participants only');
    $assert((int) $events->find($together)['party_includes_guardians'] === 1,
        'the include-guardians flag is stored');

    // The booking screens read the flag through this method, so it has to be
    // in the projection or the form silently falls back to the wrong branch.
    $ctxSession = fixture_create_session($separate, '2027-06-01 10:00:00', '2027-06-01 11:00:00', 10);
    $context = (new EventSessionRepository())->findWithContext($ctxSession);
    $assert($context !== null && array_key_exists('party_includes_guardians', $context),
        'session context carries the flag for the booking form');

    // --- escorts are recorded and do not take seats --------------------------
    $booking = $service->book($ctxSession, fixture_email('gd-a'), 'A', 2,
        companionNames: ['子ども2'], guardianCount: 2);
    $assert($booking['status'] === BookingStatus::Confirmed, 'a booking with escorts is accepted');
    $assert((int) Db::scalar('SELECT guardian_count FROM bookings WHERE id = ?',
        [$booking['booking_id']]) === 2, 'guardian_count is stored');
    $assert($seats($ctxSession) === 2, 'escorts consume no capacity - the seats are the party size');

    // Which is the number the host actually needs: 2 seats, 4 bodies.
    $arriving = (int) Db::scalar(
        'SELECT party_size + guardian_count FROM bookings WHERE id = ?',
        [$booking['booking_id']]
    );
    $assert($arriving === 4, 'party_size + guardian_count is the headcount');

    // The admin session list reads the sum from here rather than a counter.
    $listed = (new EventSessionRepository())->forEvent($separate);
    $assert(($listed[0]['confirmed_guardians'] ?? null) !== null
        && (int) $listed[0]['confirmed_guardians'] === 2,
        'the session listing sums the escorts of confirmed bookings');

    // --- capacity is still about participants --------------------------------
    // 8 seats left, and a party of 8 with 8 escorts must fit: if escorts were
    // charged, this would be refused.
    $big = $service->book($ctxSession, fixture_email('gd-b'), 'B', 8,
        companionNames: array_fill(0, 7, '子'), guardianCount: 8);
    $assert($big['status'] === BookingStatus::Confirmed,
        'a party filling the remaining seats fits even with escorts along');
    $assert($seats($ctxSession) === 10, 'the session is now full on seats alone');

    $full = false;
    try {
        $service->book($ctxSession, fixture_email('gd-c'), 'C', 1, allowWaitlist: false);
    } catch (SessionFullException $e) {
        $full = true;
    }
    $assert($full, 'and one more participant is refused');

    // Cancelling returns the seats; the escorts were never holding any.
    (new CancellationService())->cancelById((int) $big['booking_id'], 'test:guardians');
    $assert($seats($ctxSession) === 2, 'cancelling returns exactly the party size');
    $assert((int) Db::scalar('SELECT guardian_count FROM bookings WHERE id = ?',
        [$big['booking_id']]) === 8, 'the escort count survives cancellation for the record');
    $assert((int) (new EventSessionRepository())->forEvent($separate)[0]['confirmed_guardians'] === 2,
        'a cancelled booking drops out of the escort total');

    // --- events that count them get no separate number ----------------------
    $tourSession = fixture_create_session($together, '2027-06-02 10:00:00', '2027-06-02 11:00:00', 10);
    $tour = $service->book($tourSession, fixture_email('gd-d'), 'D', 3,
        companionNames: ['同行1', '同行2'], guardianCount: 2);
    $assert((int) Db::scalar('SELECT guardian_count FROM bookings WHERE id = ?',
        [$tour['booking_id']]) === 0,
        'an escort count sent for an event that counts them in 参加人数 is dropped, not doubled');
    $assert($seats($tourSession) === 3, 'and the seats are the party size, escorts included');

    // --- the ceiling --------------------------------------------------------
    $refused = false;
    try {
        $service->book($ctxSession, fixture_email('gd-e'), 'E', 1,
            guardianCount: BookingService::GUARDIAN_MAX + 1);
    } catch (ValidationException $e) {
        $refused = str_contains($e->getMessage(), '付き添い');
    }
    $assert($refused, 'more escorts than the ceiling is refused by the service, not only the form');
    $assert($seats($ctxSession) === 2, 'the refused booking took no seats');

    // The column is UNSIGNED, so a negative cannot be stored; the service
    // refuses it first so the failure is a message and not a PDOException.
    $negative = false;
    try {
        $service->book($ctxSession, fixture_email('gd-f'), 'F', 1, guardianCount: -1);
    } catch (ValidationException $e) {
        $negative = true;
    }
    $assert($negative, 'a negative escort count is refused');

    // --- the mail says so ---------------------------------------------------
    $mail = (string) Db::scalar(
        'SELECT body FROM mail_queue WHERE booking_id = ? ORDER BY id LIMIT 1',
        [$booking['booking_id']]
    );
    $assert(str_contains($mail, '付き添い') && str_contains($mail, '2 名'),
        'the confirmation mail states the escort count');

    $tourMail = (string) Db::scalar(
        'SELECT body FROM mail_queue WHERE booking_id = ? ORDER BY id LIMIT 1',
        [$tour['booking_id']]
    );
    $assert(!str_contains($tourMail, '付き添い'),
        'and says nothing about escorts where there is no separate count');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "guardians: all OK\n" : "guardians: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
