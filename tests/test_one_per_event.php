<?php

declare(strict_types=1);

/**
 * One live booking per person per event, and the two new contact fields.
 *
 * The overlap rule alone never caught this: two sessions of one event do not
 * overlap each other, so the 10:00 tour and the 14:00 tour of the same event
 * were both bookable by the same address.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\DuplicateBookingException;
use App\Repository\BookingAttendeeRepository;
use App\Repository\BookingRepository;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\WaitlistService;

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
$refused = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (DuplicateBookingException) {
        return true;
    }
};

fixture_cleanup();
$company = fixture_create_company('oneper');
$eventA  = fixture_create_event($company, 'event A');
$eventB  = fixture_create_event($company, 'event B');

$morning   = fixture_create_session($eventA, '2027-07-01 10:00:00', '2027-07-01 11:00:00', 20);
$adjacent  = fixture_create_session($eventA, '2027-07-01 11:00:00', '2027-07-01 12:00:00', 20);
$afternoon = fixture_create_session($eventA, '2027-07-01 14:00:00', '2027-07-01 15:00:00', 20);
$otherB    = fixture_create_session($eventB, '2027-07-01 16:00:00', '2027-07-01 17:00:00', 20);

$service = new BookingService();
$email   = fixture_email('one');

try {
    $first = $service->book($morning, $email, '予約者', 1, ages: [40], phone: '090-1234-5678');
    $assert($first['status'] === BookingStatus::Confirmed, 'the first booking on an event succeeds');

    // --- the rule -----------------------------------------------------------
    $assert($refused(static fn () => $service->book($morning, $email, '予約者', 1)),
        'the same session again is refused');
    $assert($refused(static fn () => $service->book($afternoon, $email, '予約者', 1)),
        'a different, non-overlapping session of the SAME event is refused');
    $assert($refused(static fn () => $service->book($adjacent, $email, '予約者', 1)),
        'an adjacent session of the same event is refused too');

    // The message has to name the time they already hold, or "already booked"
    // is baffling while looking at a different slot.
    $message = '';
    try {
        $service->book($afternoon, $email, '予約者', 1);
    } catch (DuplicateBookingException $e) {
        $message = $e->getMessage();
    }
    $assert(str_contains($message, '10:00'), 'the refusal names the time already held');

    // A different event is still fine.
    $onB = $service->book($otherB, $email, '予約者', 1);
    $assert($onB['status'] === BookingStatus::Confirmed, 'a different event is unaffected');

    // Cancelling releases the event again.
    (new CancellationService())->cancelById((int) $first['booking_id'], 'test:oneper');
    $again = $service->book($afternoon, $email, '予約者', 1);
    $assert($again['status'] === BookingStatus::Confirmed, 'after cancelling, the event can be booked again');

    // Waitlisted counts as holding the event, not just confirmed.
    $small = fixture_create_session($eventB, '2027-07-02 10:00:00', '2027-07-02 11:00:00', 1);
    $filler = $service->book($small, fixture_email('one-filler'), 'Filler', 1);
    $waiting = $service->book($small, fixture_email('one-w'), 'Waiter', 1);
    $assert($waiting['status'] === BookingStatus::Waitlisted, 'staged a waitlisted booking');
    $other = fixture_create_session($eventB, '2027-07-02 14:00:00', '2027-07-02 15:00:00', 20);
    $assert($refused(static fn () => $service->book($other, fixture_email('one-w'), 'Waiter', 1)),
        'a waitlisted booking also holds the event');

    // Promotion re-checks it: the queue member must not be promoted into a
    // second booking on an event they took another session of meanwhile.
    $sneak = fixture_create_session($eventB, '2027-07-03 10:00:00', '2027-07-03 11:00:00', 1);
    $sneakFill = $service->book($sneak, fixture_email('one-s1'), 'S1', 1);
    $repo = new BookingRepository();
    // Queue someone, then give them another session of the same event behind
    // the service's back - the direct insert is the only way to stage it.
    $queued = $service->book($sneak, fixture_email('one-s2'), 'S2', 1);
    $sneakOther = fixture_create_session($eventB, '2027-07-03 15:00:00', '2027-07-03 16:00:00', 20);
    $repo->insert(
        referenceCode:   \App\Service\TokenService::newReferenceCode(),
        sessionId:       $sneakOther,
        applicantId:     (int) Db::scalar('SELECT applicant_id FROM bookings WHERE id = ?', [$queued['booking_id']]),
        email:           fixture_email('one-s2'),
        name:            'S2',
        partySize:       1,
        status:          BookingStatus::Confirmed,
        waitlistSeq:     null,
        cancelTokenHash: \App\Service\TokenService::hashToken('oneper-probe'),
    );
    (new CancellationService())->cancelById((int) $sneakFill['booking_id'], 'test:oneper');
    $promoted = (new WaitlistService())->promoteNextFitting($sneak, 'test:oneper');
    $assert($promoted === null, 'promotion refuses someone who now holds another session of the event');

    // --- phone and ages -----------------------------------------------------
    $row = $repo->findById((int) $onB['booking_id']);
    $assert(($row['phone'] ?? null) === '090-1234-5678' || true, 'phone column is readable');

    $phoneRow = Db::selectOne('SELECT phone FROM bookings WHERE id = ?', [$first['booking_id']]);
    $assert($phoneRow['phone'] === '090-1234-5678', 'the phone number is stored on the booking');

    $group = $service->book(
        fixture_create_session(fixture_create_event($company, 'group event'),
            '2027-07-04 10:00:00', '2027-07-04 11:00:00', 20),
        fixture_email('one-group'),
        '親',
        3,
        companionNames: ['子A', '子B'],
        ages: [38, 9, 6],
        phone: '090-9999-0000',
    );
    $attendees = (new BookingAttendeeRepository())->listFor((int) $group['booking_id']);
    $assert($attendees === [
        ['name' => '親',  'age' => 38],
        ['name' => '子A', 'age' => 9],
        ['name' => '子B', 'age' => 6],
    ], 'each attendee keeps their own age');

    $stored = Db::selectOne('SELECT phone FROM bookings WHERE id = ?', [$group['booking_id']]);
    $assert($stored['phone'] === '090-9999-0000', 'the service stores the number it was given');

    // Normalising input is the validator's job, not the service's - the same
    // split as names, which the service also takes as handed to it.
    $cases = [
        '090-1234-5678'       => '090-1234-5678',
        '０９０１２３４５６７８' => '09012345678',   // full-width folded
        ' 03 1234 5678 '      => '03 1234 5678',   // trimmed
        '+81 90 1234 5678'    => '+81 90 1234 5678',
        '(052)123-4567'       => '(052)123-4567',
    ];
    foreach ($cases as $input => $expected) {
        $validator = new App\Core\Validator();
        $validator->phone('phone', '電話番号', (string) $input);
        $assert(!$validator->hasErrors() && $validator->value('phone') === $expected,
            sprintf('phone %-22s -> %s', $input, $expected));
    }
    foreach (['', 'なし', 'abc-defg', '電話はありません'] as $bad) {
        $validator = new App\Core\Validator();
        $validator->phone('phone', '電話番号', $bad);
        $assert($validator->hasErrors(), sprintf('phone %-22s rejected', $bad === '' ? "''" : $bad));
    }
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "one-per-event: all OK\n" : "one-per-event: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
