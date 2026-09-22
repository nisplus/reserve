<?php

declare(strict_types=1);

/**
 * The site-wide booking switch (App\Domain\BookingWindow, App\Core\Settings).
 *
 * The first assertion is the one that matters operationally: with no rows in
 * `settings`, bookings are open. Migration 010 can therefore be applied to a
 * live site without changing anything, and the feature ships asleep. If that
 * assertion ever fails, running migrate.php in production stops the site.
 *
 * After that, the interesting cases are the boundaries - the announced minute
 * is in, the closing minute is out - and the fact that a stop is enforced in
 * the transaction rather than only on screen. A deadline is a moment in time
 * and the form is a two-step POST, so someone is always mid-flow when it
 * passes; that person must be turned away with a message, not silently
 * allowed through.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Core\Settings;
use App\Core\View;
use App\Domain\BookingClosedReason;
use App\Domain\BookingStatus;
use App\Domain\BookingWindow;
use App\Exception\ValidationException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
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

$at = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s);

/** Run a booking and report whether it was refused for the given wording. */
$refusedWith = static function (callable $fn, string $needle): bool {
    try {
        $fn();
        return false;
    } catch (ValidationException $e) {
        return str_contains($e->getMessage(), $needle);
    }
};

// Whatever the developer's own database holds, put it back afterwards.
$saved = [
    Settings::BOOKING_ENABLED        => Settings::get(Settings::BOOKING_ENABLED),
    Settings::BOOKING_OPENS_AT       => Settings::get(Settings::BOOKING_OPENS_AT),
    Settings::BOOKING_CLOSES_AT      => Settings::get(Settings::BOOKING_CLOSES_AT),
    Settings::BOOKING_CLOSED_MESSAGE => Settings::get(Settings::BOOKING_CLOSED_MESSAGE),
];

fixture_cleanup();
$company = fixture_create_company('window');
$events  = new EventRepository();
$service = new BookingService();
$seq     = 0;

try {
    // --- the deploy-safety property -----------------------------------------
    Db::execute('DELETE FROM settings');
    Settings::forget();
    $assert((int) Db::scalar('SELECT COUNT(*) FROM settings') === 0, 'no settings rows');
    $assert(Settings::bookingWindow()->isOpenAt($at('now')),
        'with nothing configured, bookings are OPEN - migration 010 changes no behaviour');

    // --- the window itself ---------------------------------------------------
    $window = new BookingWindow(true, $at('2026-09-11 10:00:00'), $at('2026-09-30 17:00:00'));
    $assert(!$window->isOpenAt($at('2026-09-11 09:59:59')), 'a second before opening is closed');
    $assert($window->isOpenAt($at('2026-09-11 10:00:00')), 'the announced minute itself is open');
    $assert($window->isOpenAt($at('2026-09-30 16:59:59')), 'a second before closing is open');
    $assert(!$window->isOpenAt($at('2026-09-30 17:00:00')), 'the closing instant is closed');

    $assert($window->reasonAt($at('2026-09-01 00:00:00')) === BookingClosedReason::NotYetOpen
        && $window->reasonAt($at('2026-10-01 00:00:00')) === BookingClosedReason::Ended
        && $window->reasonAt($at('2026-09-20 12:00:00')) === null,
        'the reason distinguishes early from late from open');

    // The switch outranks the schedule - that is what makes it an emergency stop.
    $suspended = new BookingWindow(false, $at('2026-09-11 10:00:00'), $at('2026-09-30 17:00:00'));
    $assert($suspended->reasonAt($at('2026-09-20 12:00:00')) === BookingClosedReason::Suspended,
        'the switch off closes bookings inside the window too');

    // Half-bounded and unbounded.
    $assert((new BookingWindow(true, $at('2026-09-11 10:00:00')))->isOpenAt($at('2099-01-01 00:00:00')),
        'an opening time alone never closes');
    $assert((new BookingWindow(true, null, $at('2026-09-30 17:00:00')))->isOpenAt($at('2000-01-01 00:00:00')),
        'a closing time alone is open before it');
    $assert((new BookingWindow())->isOpenAt($at('now')), 'no bounds and the switch on is open');

    // The wording carries the date when there is one to give.
    $notice = $window->noticeAt($at('2026-09-01 00:00:00'));
    $assert(str_contains($notice, '2026-09-11') && str_contains($notice, '10:00'),
        'before opening, the notice names the date and time');
    $custom = new BookingWindow(false, null, null, '台風接近のため中止します。');
    $assert($custom->noticeAt($at('now')) === '台風接近のため中止します。',
        'a custom message replaces the generic line');
    $assert(str_contains(
        (new BookingWindow(true, $at('2099-01-01 10:00:00'), null, '詳細は公式サイトで。'))->noticeAt($at('now')),
        '詳細は公式サイトで。'
    ), 'and trails the opening date rather than replacing it');

    // --- settings round-trip -------------------------------------------------
    Settings::set(Settings::BOOKING_ENABLED, '0');
    $assert(!Settings::bookingWindow()->enabled, 'the switch persists');
    Settings::set(Settings::BOOKING_OPENS_AT, '2026-09-11 10:00:00');
    $assert(Settings::bookingWindow()->opensAt?->format('Y-m-d H:i') === '2026-09-11 10:00',
        'the opening time persists and reads back in JST');

    // A broken stored value must not take the public site down.
    Settings::set(Settings::BOOKING_OPENS_AT, 'not a date');
    $assert(Settings::bookingWindow()->opensAt === null,
        'an unparseable datetime reads as no bound rather than throwing');

    $rejected = false;
    try {
        Settings::set('booking.nonsense', '1');
    } catch (\InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected, 'an unknown setting key is refused rather than written where nothing reads it');

    // --- the transaction enforces it ----------------------------------------
    Db::execute('DELETE FROM settings');
    Settings::forget();

    $event = $events->create($company, 'window event', null, null, 0, true);
    $slot = static function (int $eventId) use (&$seq): int {
        $seq++;
        return fixture_create_session(
            $eventId,
            sprintf('2032-03-%02d 10:00:00', $seq),
            sprintf('2032-03-%02d 11:00:00', $seq),
            2
        );
    };

    $s1 = $slot($event);
    $before = $service->book($s1, fixture_email('bw-before'), 'Before', 1);
    $assert($before['status'] === BookingStatus::Confirmed, 'bookings work while open');

    Settings::set(Settings::BOOKING_ENABLED, '0');
    Settings::set(Settings::BOOKING_CLOSED_MESSAGE, '台風のため受付を止めています。');

    $assert($refusedWith(
        fn () => $service->book($s1, fixture_email('bw-during'), 'During', 1),
        '台風のため受付を止めています。'
    ), 'a stop is enforced inside the transaction, with the operator\'s wording');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$s1]) === 1,
        'and the refused booking took no seat');

    // A stop must not become a waitlist entry either - the check runs before
    // the seat decision, as the age check does.
    $full = $slot($event);
    Settings::set(Settings::BOOKING_ENABLED, '1');
    Settings::forget();
    $service->book($full, fixture_email('bw-filler'), 'Filler', 2);
    Settings::set(Settings::BOOKING_ENABLED, '0');
    $assert($refusedWith(
        fn () => $service->book($full, fixture_email('bw-queue'), 'Queue', 1),
        '受付'
    ), 'a full session refuses rather than queueing while bookings are stopped');
    $assert((int) Db::scalar(
        "SELECT COUNT(*) FROM bookings WHERE session_id = ? AND status = 'waitlisted'",
        [$full]
    ) === 0, 'and nothing joined the queue');

    // --- the office keeps working -------------------------------------------
    // A stop is about new applications. Promotion and cancellation are how the
    // office copes with the situation that caused the stop.
    Settings::set(Settings::BOOKING_ENABLED, '1');
    Settings::forget();
    $queueSlot = $slot($event);
    $holder = $service->book($queueSlot, fixture_email('bw-holder'), 'Holder', 2);
    $waiting = $service->book($queueSlot, fixture_email('bw-waiting'), 'Waiting', 1);
    $assert($waiting['status'] === BookingStatus::Waitlisted, 'someone is queued');

    Settings::set(Settings::BOOKING_ENABLED, '0');
    Settings::forget();
    (new CancellationService())->cancelById((int) $holder['booking_id'], 'test:window');
    $assert((string) Db::scalar('SELECT status FROM bookings WHERE id = ?', [$holder['booking_id']])
        === 'cancelled', 'cancellation still works while bookings are stopped');
    (new WaitlistService())->promote((int) $waiting['booking_id'], 'test:window');
    $assert((string) Db::scalar('SELECT status FROM bookings WHERE id = ?', [$waiting['booking_id']])
        === 'confirmed', 'and so does promotion - the stop is about new applications only');

    // --- 予約不要 events are untouched ---------------------------------------
    $free = $events->create($company, 'window drop-in', null, null, 0, true, bookingRequired: false);
    $freeSlot = fixture_create_session($free, '2032-04-01 10:00:00', '2032-04-01 11:00:00', 5);
    $assert($refusedWith(
        fn () => $service->book($freeSlot, fixture_email('bw-free'), 'Free', 1),
        '予約不要'
    ), 'a 予約不要 event still refuses for its own reason, not the stop');

    // --- the screens keep the information, lose the way in -------------------
    $sessions = new EventSessionRepository();
    $render = static function (int $eventId) use ($events, $sessions): string {
        $window = Settings::bookingWindow();
        $now = new DateTimeImmutable('now');
        $slots = $sessions->forEvent($eventId, true);
        return View::renderPartial('pub/event_show', [
            'event'  => $events->findWithCompany($eventId),
            'days'   => $sessions->groupByDate($slots),
            'total'  => count($slots),
            'closed' => $window->reasonAt($now),
            'closedNotice' => $window->noticeAt($now),
        ]);
    };

    Settings::set(Settings::BOOKING_ENABLED, '1');
    Settings::forget();
    $openHtml = $render($event);
    $assert(str_contains($openHtml, '/apply'), 'while open, the slot list offers a way in');

    Settings::set(Settings::BOOKING_ENABLED, '0');
    Settings::forget();
    $shutHtml = $render($event);
    $assert(!str_contains($shutHtml, '/apply'),
        'while stopped, no booking link is offered anywhere on the page');
    $assert(str_contains($shutHtml, '10:00') && str_contains($shutHtml, '11:00'),
        'but the times are still there - a stop hides the way in, not the programme');
    $assert(str_contains($shutHtml, '台風のため受付を止めています。'),
        'and the reason is stated');
    $assert(str_contains($shutHtml, '受付停止中') && !str_contains($shutHtml, '残り'),
        'the seat count gives way to the reason, so nothing invites a press');
} finally {
    Db::execute('DELETE FROM settings');
    foreach ($saved as $name => $value) {
        if ($value !== null) {
            Settings::set($name, $value);
        }
    }
    Settings::forget();
    fixture_cleanup();
}

echo $failures === 0 ? "booking window: all OK\n" : "booking window: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
