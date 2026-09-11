<?php

declare(strict_types=1);

/**
 * Per-event age limits (events.min_age / events.max_age).
 *
 * Either end may be absent and so may both, so the interesting cases are the
 * half-bounded ones and the boundary values themselves - off by one here means
 * turning away someone the event was written for.
 *
 * The rule applies to every age the booking collects, with no branch for
 * escorts, and the reason there is no branch is worth holding in place: where
 * 参加人数 excludes them they have no age recorded at all, and where it
 * includes them they are participants. Both are asserted below.
 *
 * It runs before the seat decision, so an application outside the range cannot
 * become a waitlist entry either.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\AgeRange;
use App\Domain\BookingStatus;
use App\Exception\ValidationException;
use App\Repository\EventRepository;
use App\Repository\EventSessionRepository;
use App\Service\BookingService;

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

/** Run a booking and report whether it was refused for being out of range. */
$refused = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (ValidationException $e) {
        return str_contains($e->getMessage(), '対象年齢の範囲外');
    }
};

fixture_cleanup();
$company = fixture_create_company('agelimit');
$events  = new EventRepository();
$service = new BookingService();
$seq     = 0;

try {
    // --- the value object ---------------------------------------------------
    $both = new AgeRange(6, 12);
    $assert(!$both->accepts(5) && $both->accepts(6) && $both->accepts(12) && !$both->accepts(13),
        'both ends are inclusive');
    $assert((new AgeRange(6, null))->accepts(120) && !(new AgeRange(6, null))->accepts(5),
        'a lower bound alone leaves the top open');
    $assert((new AgeRange(null, 12))->accepts(0) && !(new AgeRange(null, 12))->accepts(13),
        'an upper bound alone leaves the bottom open');
    $assert((new AgeRange())->isUnbounded() && (new AgeRange())->accepts(0)
        && (new AgeRange())->accepts(120),
        'no bounds accepts anyone');

    // 0 is a bound, not an absence - the distinction the form has to preserve.
    $assert(!(new AgeRange(null, 0))->isUnbounded() && (new AgeRange(null, 0))->accepts(0)
        && !(new AgeRange(null, 0))->accepts(1),
        'an upper bound of 0 is a real limit, not unset');

    $assert($both->label() === '6 歳〜12 歳'
        && (new AgeRange(6, null))->label() === '6 歳以上'
        && (new AgeRange(null, 12))->label() === '12 歳以下'
        && (new AgeRange(18, 18))->label() === '18 歳'
        && (new AgeRange())->label() === '',
        'the label reads correctly for all four shapes');

    // A stored pair the wrong way round must not reject everyone.
    $assert(AgeRange::fromEvent(['min_age' => 12, 'max_age' => 6])->isUnbounded(),
        'reversed bounds fall back to unbounded rather than refusing every age');
    $assert(AgeRange::fromEvent([])->isUnbounded(),
        'a row without the columns is unbounded, not min 0');

    // --- stored and read back ------------------------------------------------
    $bounded = $events->create($company, 'aged 6-12', null, null, 0, true, minAge: 6, maxAge: 12);
    $row = $events->find($bounded);
    $assert((int) $row['min_age'] === 6 && (int) $row['max_age'] === 12, 'both bounds stored');

    $open = $events->create($company, 'no limit', null, null, 0, true);
    $assert($events->find($open)['min_age'] === null && $events->find($open)['max_age'] === null,
        'events default to no age limit');

    $floorOnly = $events->create($company, 'aged 18 and up', null, null, 0, true, minAge: 18);
    $assert((int) $events->find($floorOnly)['min_age'] === 18
        && $events->find($floorOnly)['max_age'] === null,
        'a lower bound alone is storable');

    // The booking form reads the limits through this projection.
    $probe = fixture_create_session($bounded, '2029-01-01 09:00:00', '2029-01-01 09:30:00', 10);
    $ctx = (new EventSessionRepository())->findWithContext($probe);
    $assert($ctx !== null && (int) $ctx['min_age'] === 6 && (int) $ctx['max_age'] === 12,
        'session context carries the limits for the form');

    // --- the service enforces it, past any form ------------------------------
    $slot = static function (int $eventId) use (&$seq): int {
        $seq++;
        return fixture_create_session(
            $eventId,
            sprintf('2029-02-%02d 10:00:00', $seq),
            sprintf('2029-02-%02d 11:00:00', $seq),
            10
        );
    };

    $s1 = $slot($bounded);
    $assert($refused(fn () => $service->book($s1, fixture_email('al-young'), 'Young', 1, ages: [5])),
        'below the lower bound is refused by the service');
    $assert($refused(fn () => $service->book($s1, fixture_email('al-old'), 'Old', 1, ages: [13])),
        'above the upper bound is refused');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$s1]) === 0,
        'a refused booking takes no seats');

    $ok = $service->book($s1, fixture_email('al-six'), 'Six', 1, ages: [6]);
    $assert($ok['status'] === BookingStatus::Confirmed, 'exactly the lower bound is accepted');
    $s2 = $slot($bounded);
    $ok2 = $service->book($s2, fixture_email('al-twelve'), 'Twelve', 1, ages: [12]);
    $assert($ok2['status'] === BookingStatus::Confirmed, 'exactly the upper bound is accepted');

    // One person out of range spoils the party, whichever position they hold.
    $s3 = $slot($bounded);
    $assert($refused(fn () => $service->book($s3, fixture_email('al-party'), 'Party', 3,
        companionNames: ['二人目', '三人目'], ages: [8, 10, 40])),
        'a party is refused when any one member is out of range');
    $assert($refused(fn () => $service->book($s3, fixture_email('al-party2'), 'Party2', 2,
        companionNames: ['二人目'], ages: [40, 8])),
        'including when the one out of range is first');

    // Half-bounded and unbounded events.
    $s4 = $slot($floorOnly);
    $assert($refused(fn () => $service->book($s4, fixture_email('al-17'), 'Seventeen', 1, ages: [17])),
        'a lower bound alone still refuses below it');
    $assert($service->book($s4, fixture_email('al-99'), 'NinetyNine', 1, ages: [99])['status']
        === BookingStatus::Confirmed,
        'and accepts any age above it');

    $s5 = $slot($open);
    $assert($service->book($s5, fixture_email('al-any'), 'Zero', 1, ages: [0])['status']
        === BookingStatus::Confirmed,
        'an event with no limits accepts any age');

    // A CLI caller that supplies no ages is not blocked - there is nothing to
    // judge, and inventing an age to reject it on would be worse.
    $s6 = $slot($bounded);
    $assert($service->book($s6, fixture_email('al-noage'), 'NoAge', 1)['status']
        === BookingStatus::Confirmed,
        'ages omitted entirely are not judged');

    // --- waitlist registration is covered too --------------------------------
    // The check runs before the seat decision, so a full session refuses an
    // out-of-range applicant rather than queueing someone unpromotable.
    $full = fixture_create_session($bounded, '2029-03-01 10:00:00', '2029-03-01 11:00:00', 1);
    $service->book($full, fixture_email('al-holder'), 'Holder', 1, ages: [10]);
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$full]) === 1,
        'the session is full');

    $assert($refused(fn () => $service->book($full, fixture_email('al-wait-bad'), 'Thirty', 1, ages: [30])),
        'an out-of-range applicant is refused rather than waitlisted');
    $assert((int) Db::scalar(
        "SELECT COUNT(*) FROM bookings WHERE session_id = ? AND status = 'waitlisted'",
        [$full]
    ) === 0, 'and nothing was added to the queue');

    $waited = $service->book($full, fixture_email('al-wait-ok'), 'Ten', 1, ages: [10]);
    $assert($waited['status'] === BookingStatus::Waitlisted,
        'an in-range applicant still joins the queue normally');

    // --- escorts -------------------------------------------------------------
    // Counted separately: they have no age on the record, so there is nothing
    // to test them against and their presence cannot fail the booking.
    $s7 = $slot($bounded);
    $sep = $service->book($s7, fixture_email('al-sep'), 'Child', 1, ages: [8], guardianCount: 2);
    $assert($sep['status'] === BookingStatus::Confirmed,
        'escorts counted separately do not fail the check - no age is held for them');
    $assert((int) Db::scalar('SELECT guardian_count FROM bookings WHERE id = ?',
        [$sep['booking_id']]) === 2, 'and they are still recorded');

    // Counted in the party: they are participants, ages and all, so the limit
    // applies to them exactly as the requirement says.
    $together = $events->create($company, 'aged 6-12, guardians counted', null, null, 0, true,
        partyIncludesGuardians: true, minAge: 6, maxAge: 12);
    $s8 = $slot($together);
    $assert($refused(fn () => $service->book($s8, fixture_email('al-tog'), 'WithParent', 2,
        companionNames: ['保護者'], ages: [8, 40])),
        'a guardian inside 参加人数 is subject to the limit');
    $assert($service->book($s8, fixture_email('al-tog2'), 'Siblings', 2,
        companionNames: ['弟'], ages: [8, 10])['status'] === BookingStatus::Confirmed,
        'and the same booking passes when everyone is in range');

    // --- a limit tightened later does not cancel what was taken --------------
    // Same treatment max_party_size gets: existing bookings survive.
    $events->update($bounded, $company, 'aged 6-12', null, null, 0, true,
        bookingRequired: true, externalUrl: null, maxPartySize: 20,
        minAge: 30, maxAge: 40);
    $assert((string) Db::scalar('SELECT status FROM bookings WHERE id = ?', [$ok['booking_id']])
        === 'confirmed',
        'tightening the range leaves bookings already taken alone');
    $s9 = $slot($bounded);
    $assert($refused(fn () => $service->book($s9, fixture_email('al-after'), 'After', 1, ages: [10])),
        'but applies to applications from then on');
    // --- the form tells people before they type ------------------------------
    // renderPartial, not render: the layout would pull in the flash partial.
    $render = static function (int $sessionId): string {
        return App\Core\View::renderPartial('pub/booking_apply', [
            'session'  => (new EventSessionRepository())->findWithContext($sessionId),
            'errors'   => [],
            'old'      => [],
            'maxParty' => 10,
        ]);
    };
    $shown = $render($probe);
    $assert(str_contains($shown, '対象年齢') && str_contains($shown, '30 歳〜40 歳'),
        'the booking form states the range, so it is known before submitting');

    $unlimited = fixture_create_session($open, '2029-04-01 10:00:00', '2029-04-01 11:00:00', 10);
    $assert(!str_contains($render($unlimited), '対象年齢'),
        'and says nothing about it when the event has no limit');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "age limits: all OK\n" : "age limits: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
