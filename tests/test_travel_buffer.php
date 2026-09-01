<?php

declare(strict_types=1);

/**
 * Travel buffer: bookings that do not overlap but sit within
 * travel_buffer.minutes of each other. Boundary is inclusive (a gap of
 * exactly 15 minutes triggers with minutes=15), both directions count
 * (existing booking before or after the new one), and the two modes differ:
 * warn lets the booking through (the popup lives on the confirmation
 * screen), block refuses it inside the transaction.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Config;
use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\DuplicateBookingException;
use App\Exception\TravelBufferException;
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

/** Run $fn with travel_buffer set to the given values, restoring afterwards. */
$withBuffer = static function (int $minutes, bool $block, callable $fn): void {
    $config = require dirname(__DIR__) . '/config/config.php';
    $config['travel_buffer'] = ['minutes' => $minutes, 'block' => $block];
    Config::load($config);
    try {
        $fn();
    } finally {
        unset($config['travel_buffer']);
        Config::load($config);
    }
};

$sessionRow = static fn (int $id): array => Db::selectOne(
    'SELECT id, starts_at, ends_at FROM event_sessions WHERE id = ?', [$id]
) ?? [];

fixture_cleanup();
$company = fixture_create_company('travel');

/**
 * Each session gets its OWN event. Travel time is about moving between
 * different events; two sessions of one event are refused outright by the
 * one-booking-per-event rule, which would mask what is being tested here.
 */
$sessionUnderOwnEvent = static function (string $label, string $start, string $end) use ($company): int {
    return fixture_create_session(fixture_create_event($company, $label), $start, $end, 5);
};

$service = new BookingService();

try {
    // Base booking: 10:00-11:00. Neighbours at every interesting distance.
    $base     = $sessionUnderOwnEvent('base',     '2027-03-01 10:00:00', '2027-03-01 11:00:00');
    $adjacent = $sessionUnderOwnEvent('adjacent', '2027-03-01 11:00:00', '2027-03-01 11:45:00'); // gap 0
    $gap15    = $sessionUnderOwnEvent('gap15',    '2027-03-01 11:15:00', '2027-03-01 12:00:00'); // gap 15
    $gap16    = $sessionUnderOwnEvent('gap16',    '2027-03-01 11:16:00', '2027-03-01 12:00:00'); // gap 16
    $before15 = $sessionUnderOwnEvent('before15', '2027-03-01 08:45:00', '2027-03-01 09:45:00'); // ends 15 before base

    $service->book($base, fixture_email('tb-p'), 'P', 1);

    // --- detection boundaries (warn mode, default 15 minutes) ---------------
    $withBuffer(15, false, function () use ($assert, $service, $sessionRow, $adjacent, $gap15, $gap16, $before15) {
        $email = fixture_email('tb-p');

        $warn = $service->travelBufferWarning($email, $sessionRow($adjacent));
        $assert($warn !== null && $warn['gap_minutes'] === 0, 'gap 0 (adjacent slots) is flagged');

        $warn = $service->travelBufferWarning($email, $sessionRow($gap15));
        $assert($warn !== null && $warn['gap_minutes'] === 15, 'gap of exactly 15 minutes is flagged (inclusive)');

        $assert($service->travelBufferWarning($email, $sessionRow($gap16)) === null,
            'gap of 16 minutes is not flagged');

        $warn = $service->travelBufferWarning($email, $sessionRow($before15));
        $assert($warn !== null && $warn['gap_minutes'] === 15,
            'a booking AFTER the new one is flagged too (both directions)');

        $assert($service->travelBufferWarning(fixture_email('tb-nobody'), $sessionRow($gap15)) === null,
            'an address with no bookings gets no warning (and no applicant row is created)');
        $assert(Db::scalar('SELECT id FROM applicants WHERE email = ?', [fixture_email('tb-nobody')]) === null,
            'the advisory lookup did not create an applicant');

        // Warn mode: the booking itself goes through.
        $booked = $service->book($sessionRow($gap15)['id'] ? (int) $sessionRow($gap15)['id'] : 0, $email, 'P', 1);
        $assert($booked['status'] === BookingStatus::Confirmed, 'warn mode still books (popup is the only gate)');
    });

    // --- buffer disabled ------------------------------------------------------
    // A fresh person with only a base booking, so the adjacent slot is clean
    // of overlaps and the only thing standing between them is the buffer.
    $zBase = $sessionUnderOwnEvent('z-base', '2027-03-01 13:00:00', '2027-03-01 14:00:00');
    $zAdj  = $sessionUnderOwnEvent('z-adj',  '2027-03-01 14:00:00', '2027-03-01 14:45:00');
    $service->book($zBase, fixture_email('tb-z'), 'Z', 1);
    $withBuffer(0, true, function () use ($assert, $service, $sessionRow, $zAdj) {
        $assert($service->travelBufferWarning(fixture_email('tb-z'), $sessionRow($zAdj)) === null,
            'minutes = 0 disables the check entirely');
        $booked = $service->book($zAdj, fixture_email('tb-z'), 'Z', 1);
        $assert($booked['status'] === BookingStatus::Confirmed, 'block flag is inert while disabled');
    });

    // --- block mode refuses inside the transaction ----------------------------
    $blockBase = $sessionUnderOwnEvent('block-base', '2027-03-02 10:00:00', '2027-03-02 11:00:00');
    $blockNear = $sessionUnderOwnEvent('block-near', '2027-03-02 11:10:00', '2027-03-02 12:00:00'); // gap 10
    $blockLap  = $sessionUnderOwnEvent('block-lap',  '2027-03-02 10:30:00', '2027-03-02 11:30:00'); // overlaps
    $service->book($blockBase, fixture_email('tb-q'), 'Q', 1);

    $withBuffer(15, true, function () use ($assert, $service, $blockNear, $blockLap) {
        $refused = false;
        try {
            $service->book($blockNear, fixture_email('tb-q'), 'Q', 1);
        } catch (TravelBufferException $e) {
            $refused = str_contains($e->getMessage(), '移動時間を考慮すると、この予約は間に合いません');
        }
        $assert($refused, 'block mode refuses with the required message');
        $assert((int) Db::scalar(
            'SELECT COUNT(*) FROM bookings b JOIN applicants a ON a.id = b.applicant_id
             WHERE a.email = ? AND b.session_id = ?',
            [fixture_email('tb-q'), $blockNear]
        ) === 0, 'nothing was written by the refused booking');

        // A true overlap is still the duplicate error, not a travel warning.
        $overlap = false;
        try {
            $service->book($blockLap, fixture_email('tb-q'), 'Q', 1);
        } catch (DuplicateBookingException) {
            $overlap = true;
        }
        $assert($overlap, 'an actual overlap still reports as a duplicate, not a travel problem');
    });

    // --- promotion honours block mode -----------------------------------------
    // T waits on a full session whose start is 10 minutes after T's other
    // confirmed booking ends. Warn mode promotes (the applicant accepted the
    // popup when they queued); block mode skips T.
    $tightEvent = fixture_create_event($company, 'tight');
    $tight    = fixture_create_session($tightEvent, '2027-03-03 12:00:00', '2027-03-03 13:00:00', 1);
    $tOther   = $sessionUnderOwnEvent('t-other', '2027-03-03 10:30:00', '2027-03-03 11:50:00'); // ends 10 before tight
    $holder   = $service->book($tight, fixture_email('tb-r'), 'R', 1);
    $withBuffer(15, false, function () use ($service, $tight, $tOther) {
        $service->book($tOther, fixture_email('tb-t'), 'T', 1);   // confirmed
        $service->book($tight, fixture_email('tb-t'), 'T', 1);    // waitlisted (warned, accepted)
    });
    (new CancellationService())->cancelById((int) $holder['booking_id'], 'test:travel');

    $waitlist = new WaitlistService();
    $withBuffer(15, true, function () use ($assert, $waitlist, $tight) {
        $assert($waitlist->promoteNextFitting($tight, 'test:travel') === null,
            'block mode: promotion skips the candidate who cannot make it in time');
    });
    $withBuffer(15, false, function () use ($assert, $waitlist, $tight) {
        $promoted = $waitlist->promoteNextFitting($tight, 'test:travel');
        $assert($promoted !== null, 'warn mode: promotion proceeds (the applicant accepted the gap when queueing)');
    });
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "travel buffer: all OK\n" : "travel buffer: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
