<?php

declare(strict_types=1);

/**
 * A seat freed by a cancellation belongs to the queue, not to whoever applies
 * next (BookingService::wouldWaitlist).
 *
 * Auto-promotion is deliberately off, so between a cancellation and the office
 * acting on it there is a window where the session has free seats and people
 * waiting. Without this rule the freed seat went back on general sale for that
 * whole window, and a late applicant was confirmed ahead of everyone in line.
 *
 * The screens have to agree with the transaction, so the display flags are
 * asserted here too - a slot reading 残り 2 名 next to a button that only
 * joins a queue is the same defect wearing a different hat.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Core\View;
use App\Domain\BookingStatus;
use App\Exception\SessionFullException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\WaitlistService;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// Before the first line of output: booking_apply renders a CSRF field, and a
// session cannot be started once CLI output has begun.
App\Core\SessionManager::start();

$failures = 0;
$assert = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'OK  ' : 'NG  ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

$seats = static fn (int $id): int => (int) Db::scalar(
    'SELECT confirmed_seats FROM event_sessions WHERE id = ?',
    [$id]
);
$statusOf = static fn (int $id): string => (string) Db::scalar(
    'SELECT status FROM bookings WHERE id = ?',
    [$id]
);

fixture_cleanup();
$company  = fixture_create_company('queue');
$eventId  = (new EventRepository())->create($company, 'queue priority', null, null, 0, true);
$service  = new BookingService();
$sessions = new EventSessionRepository();

try {
    // --- the reported sequence ----------------------------------------------
    // cap 2, filled by A(2). Queue: B(1, seq1), C(1, seq2). Then A cancels.
    $s = fixture_create_session($eventId, '2027-11-01 10:00:00', '2027-11-01 11:00:00', 2);
    $a = $service->book($s, fixture_email('q-a'), 'A', 2);
    $b = $service->book($s, fixture_email('q-b'), 'B', 1);
    $c = $service->book($s, fixture_email('q-c'), 'C', 1);
    $assert($b['status'] === BookingStatus::Waitlisted && $c['status'] === BookingStatus::Waitlisted,
        'B and C are waitlisted behind a full session');

    (new CancellationService())->cancelById((int) $a['booking_id'], 'test:queue');
    $assert($seats($s) === 0, 'cancelling returns the seats to the session');
    $assert($statusOf((int) $b['booking_id']) === 'waitlisted'
        && $statusOf((int) $c['booking_id']) === 'waitlisted',
        'and promotes nobody by itself - that stays a decision for the office');

    // The point of the whole exercise.
    $d = $service->book($s, fixture_email('q-d'), 'D', 1);
    $assert($d['status'] === BookingStatus::Waitlisted,
        'a later applicant joins the queue rather than taking the freed seat');
    $assert($seats($s) === 0, 'the freed seat is still free, held for the queue');
    $assert((int) Db::scalar('SELECT waitlist_seq FROM bookings WHERE id = ?', [$d['booking_id']]) === 3,
        'and joins it behind the people already waiting');

    // The office promotes; the seat goes to seq 1.
    (new WaitlistService())->promote((int) $b['booking_id'], 'test:queue');
    $assert($statusOf((int) $b['booking_id']) === 'confirmed', 'promotion confirms the head of the queue');
    $assert($seats($s) === 1, 'and takes one of the two free seats');

    // C and D are still waiting, so the remaining seat is still not on sale.
    $e = $service->book($s, fixture_email('q-e'), 'E', 1);
    $assert($e['status'] === BookingStatus::Waitlisted,
        'the rule holds while anyone is left waiting');

    // Drain the queue, and the session behaves normally again.
    foreach ([$c, $d, $e] as $queued) {
        (new CancellationService())->cancelById((int) $queued['booking_id'], 'test:queue');
    }
    $assert((int) Db::scalar(
        "SELECT COUNT(*) FROM bookings WHERE session_id = ? AND status = 'waitlisted'",
        [$s]
    ) === 0, 'the queue is empty');
    $f = $service->book($s, fixture_email('q-f'), 'F', 1);
    $assert($f['status'] === BookingStatus::Confirmed,
        'with nobody waiting, a free seat is bookable again');

    // --- the caller that declines the waitlist ------------------------------
    // Seats are free but the queue holds them, so this is a refusal, and it
    // must not advertise the seats it is refusing.
    $s2 = fixture_create_session($eventId, '2027-11-02 10:00:00', '2027-11-02 11:00:00', 2);
    $g = $service->book($s2, fixture_email('q-g'), 'G', 2);
    $service->book($s2, fixture_email('q-h'), 'H', 1);            // queue: seq 1
    (new CancellationService())->cancelById((int) $g['booking_id'], 'test:queue');
    $assert($seats($s2) === 0, 'two seats free, one person waiting');

    $refusedWith = null;
    try {
        $service->book($s2, fixture_email('q-i'), 'I', 1, allowWaitlist: false);
    } catch (SessionFullException $e2) {
        $refusedWith = $e2->remaining;
    }
    $assert($refusedWith === 0,
        'declining the waitlist is refused, reporting no seats rather than the ones the queue holds');

    // --- the screens say the same thing -------------------------------------
    $context = $sessions->findWithContext($s2);
    $assert($context !== null && (int) $context['waitlist_count'] === 1,
        'the booking context carries the live queue length');
    $assert(BookingService::wouldWaitlist(
        (int) $context['seats_left'],
        1,
        (int) $context['waitlist_count']
    ), 'and the shared helper agrees with the transaction');

    // renderPartial: the layout would need a session, which CLI cannot start
    // once output has begun.
    $html = View::renderPartial('pub/event_show', [
        'event' => (new EventRepository())->findWithCompany($eventId),
        'days'  => $sessions->groupByDate($sessions->forEvent($eventId, true)),
        'total' => 2,
    ]);
    // What is asserted is the offer and the disclosure, not the badge wording -
    // that is copy, and pinning it here turns an editorial change into a broken
    // test (it already did once).
    $assert(str_contains($html, 'キャンセル待ちで予約する'),
        'the slot list offers the waitlist rather than a booking');
    $assert(str_contains($html, '現在 1 件'),
        'and says how many people are already in it');
    $assert(!str_contains($html, '残り 2 名'),
        'and does not advertise seats that cannot be booked');

    $apply = View::renderPartial('pub/booking_apply', [
        'session'  => $context,
        'errors'   => [],
        'old'      => [],
        'maxParty' => 10,
    ]);
    $assert(str_contains($apply, 'お待ちの方から順にご案内'),
        'the booking form explains why a free seat cannot be taken');
    $assert(str_contains($apply, 'キャンセル待ちで確認画面へ'),
        'and its button says so');

    // The event card sums only seats a new applicant could take.
    $card = null;
    foreach ((new EventRepository())->publishedCatalogue() as $row) {
        if ((int) $row['id'] === $eventId) {
            $card = $row;
        }
    }
    // The fixture event is unpublished, so it is absent from the catalogue -
    // publish it just for this assertion and hide it again.
    if ($card === null) {
        Db::execute('UPDATE companies SET is_published = 1 WHERE id = ?', [$company]);
        Db::execute('UPDATE events SET is_published = 1 WHERE id = ?', [$eventId]);
        foreach ((new EventRepository())->publishedCatalogue() as $row) {
            if ((int) $row['id'] === $eventId) {
                $card = $row;
            }
        }
        Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$company]);
        Db::execute('UPDATE events SET is_published = 0 WHERE id = ?', [$eventId]);
    }
    $assert($card !== null && (int) $card['waiting_count'] === 1,
        'the catalogue reports the queue length for the card badge');

    // s is full again (B promoted, then F). s2 has both seats physically free
    // but one person waiting, so it offers none of them - which is the whole
    // difference between the two figures below.
    $physicallyFree = (int) Db::scalar(
        "SELECT SUM(CAST(capacity AS SIGNED) - CAST(confirmed_seats AS SIGNED))
         FROM event_sessions WHERE event_id = ? AND status = 'open'",
        [$eventId]
    );
    $assert($physicallyFree === 2, 'two seats are physically free across the event');
    $assert($card !== null && (int) $card['seats_left'] === 0,
        'and the card offers none of them, because a queue holds them');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "queue priority: all OK\n" : "queue priority: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
