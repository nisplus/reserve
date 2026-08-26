<?php

declare(strict_types=1);

/**
 * The per-event cap on one application's party size, and the attendee names
 * collected once a party is larger than one.
 *
 * The cap is checked in the booking transaction as well as the form, so these
 * assertions go through BookingService: a hand-made POST past the form must
 * not get further than a typed one.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\ValidationException;
use App\Repository\BookingAttendeeRepository;
use App\Repository\EventRepository;
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

fixture_cleanup();
$company   = fixture_create_company('party');
$events    = new EventRepository();
$service   = new BookingService();
$attendees = new BookingAttendeeRepository();

try {
    // --- the cap ------------------------------------------------------------
    $capped = $events->create($company, 'capped event', null, null, 0, true, maxPartySize: 3);
    $assert((int) $events->find($capped)['max_party_size'] === 3, 'max_party_size stored on the event');

    $session = fixture_create_session($capped, '2027-05-01 10:00:00', '2027-05-01 11:00:00', 30);

    $refused = false;
    try {
        $service->book($session, fixture_email('pt-over'), 'Over', 4);
    } catch (ValidationException $e) {
        $refused = str_contains($e->getMessage(), '3 名まで');
    }
    $assert($refused, 'a party over the cap is refused by the service, not just the form');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$session]) === 0,
        'the refused booking took no seats');

    $atCap = $service->book($session, fixture_email('pt-atcap'), 'AtCap', 3,
        companionNames: ['二人目', '三人目']);
    $assert($atCap['status'] === BookingStatus::Confirmed, 'a party exactly at the cap is accepted');

    // Default events keep the old behaviour.
    $uncapped = $events->create($company, 'default event', null, null, 0, true);
    $assert((int) $events->find($uncapped)['max_party_size'] === 20,
        'events default to 20, the previous hard-coded limit');

    // Lowering the cap does not disturb bookings already taken.
    $events->update($capped, $company, 'capped event', null, null, 0, true,
        bookingRequired: true, externalUrl: null, maxPartySize: 1);
    $assert((int) Db::scalar('SELECT party_size FROM bookings WHERE id = ?', [$atCap['booking_id']]) === 3,
        'an existing booking survives the cap being lowered under it');
    $stillRefused = false;
    try {
        $service->book($session, fixture_email('pt-after'), 'After', 2);
    } catch (ValidationException) {
        $stillRefused = true;
    }
    $assert($stillRefused, 'the lowered cap applies to new applications immediately');
    $events->update($capped, $company, 'capped event', null, null, 0, true,
        bookingRequired: true, externalUrl: null, maxPartySize: 10);

    // --- attendee names -----------------------------------------------------
    $names = $attendees->namesFor((int) $atCap['booking_id']);
    $assert($names === ['AtCap', '二人目', '三人目'],
        'the applicant is attendee 1 and companions follow in order');

    $solo = $service->book($session, fixture_email('pt-solo'), 'Solo', 1);
    $assert($attendees->namesFor((int) $solo['booking_id']) === ['Solo'],
        'a party of one still records the applicant');

    // Extra names beyond the party size are dropped rather than stored.
    $trimmed = $service->book($session, fixture_email('pt-trim'), 'Trim', 2,
        companionNames: ['本物', '余分', 'さらに余分']);
    $assert($attendees->namesFor((int) $trimmed['booking_id']) === ['Trim', '本物'],
        'companions past party_size are ignored');

    // A blank companion leaves a gap rather than storing an empty person.
    $sparse = $service->book($session, fixture_email('pt-sparse'), 'Sparse', 3,
        companionNames: ['', '三人目だけ']);
    $sparseNames = $attendees->namesFor((int) $sparse['booking_id']);
    $assert($sparseNames === ['Sparse', '三人目だけ'], 'a blank name is skipped, not stored empty');
    $assert((int) Db::scalar(
        'SELECT attendee_no FROM booking_attendees WHERE booking_id = ? ORDER BY attendee_no DESC LIMIT 1',
        [$sparse['booking_id']]
    ) === 3, 'the numbering keeps the blank slot, so name 2 is not renumbered to 1');

    // --- attendees are bound to the booking ---------------------------------
    $many = $attendees->namesForMany([
        (int) $atCap['booking_id'], (int) $solo['booking_id'], 999999,
    ]);
    $assert(($many[(int) $atCap['booking_id']] ?? []) === ['AtCap', '二人目', '三人目']
        && ($many[(int) $solo['booking_id']] ?? []) === ['Solo']
        && !isset($many[999999]), 'namesForMany groups by booking and omits unknown ids');

    // Cancelling leaves the record - it is who applied, not who is coming.
    (new CancellationService())->cancelById((int) $atCap['booking_id'], 'test:party');
    $assert(count($attendees->namesFor((int) $atCap['booking_id'])) === 3,
        'cancelling keeps the attendee record for the audit trail');

    // Deleting a booking takes them along (ON DELETE CASCADE).
    Db::execute('DELETE FROM bookings WHERE id = ?', [$solo['booking_id']]);
    $assert($attendees->namesFor((int) $solo['booking_id']) === [],
        'deleting a booking cascades to its attendees');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "party & attendees: all OK\n" : "party & attendees: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
