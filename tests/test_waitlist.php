<?php

declare(strict_types=1);

/**
 * First-fit promotion (WaitlistService::promoteNextFitting / autoPromote):
 * the OLDEST waitlisted booking whose party_size fits the free seats is
 * promoted; a too-large group at the head is passed over without blocking the
 * queue, and order is never reshuffled among candidates who both fit.
 *
 * Also covered: the overlap re-check on promotion (a candidate who acquired a
 * clashing booking while waiting is skipped, not promoted into a double
 * booking), and the auto_promote chain after a cancellation.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Config;
use App\Core\Db;
use App\Domain\BookingStatus;
use App\Repository\BookingRepository;
use App\Service\BookingService;
use App\Service\CancellationService;
use App\Service\TokenService;
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
$status = static fn (int $id): string => (string) Db::scalar('SELECT status FROM bookings WHERE id = ?', [$id]);
$seats  = static fn (int $id): int => (int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$id]);

fixture_cleanup();
$company = fixture_create_company('waitlist');
$eventId = fixture_create_event($company, 'first-fit');

$service  = new BookingService();
$waitlist = new WaitlistService();

try {
    // --- 1. first-fit picks the oldest that fits, passing over a large head --
    // cap 4, filled by X(4). Queue: B(3, seq1), C(2, seq2), D(1, seq3).
    $s1 = fixture_create_session($eventId, '2027-02-01 10:00:00', '2027-02-01 11:00:00', 4);
    $x = $service->book($s1, fixture_email('wl-x'), 'X', 4);
    $b = $service->book($s1, fixture_email('wl-b'), 'B', 3);
    $c = $service->book($s1, fixture_email('wl-c'), 'C', 2);
    $d = $service->book($s1, fixture_email('wl-d'), 'D', 1);
    $assert($b['status'] === BookingStatus::Waitlisted && $d['waitlist_seq'] === 3, 'queue staged: B,C,D waiting');

    // Free 4 seats, then promote one at a time to watch the selection order.
    (new CancellationService())->cancelById((int) $x['booking_id'], 'test:waitlist');
    $assert($seats($s1) === 0, 'four seats free');

    $first = $waitlist->promoteNextFitting($s1, 'test:waitlist');
    $assert($first !== null && (int) $first['id'] === (int) $b['booking_id'],
        'B (oldest, fits 4) promoted first - no skipping when the head fits');
    $assert($seats($s1) === 3, 'three of four seats now taken');

    // Free = 1. C(2) does not fit; D(1), although younger, does: pass-over.
    $second = $waitlist->promoteNextFitting($s1, 'test:waitlist');
    $assert($second !== null && (int) $second['id'] === (int) $d['booking_id'],
        'gap of 1: C(2) is passed over, D(1) promoted');
    $assert($status((int) $c['booking_id']) === 'waitlisted', 'C stays waitlisted, undisturbed');

    // Nothing fits any more.
    $assert($waitlist->promoteNextFitting($s1, 'test:waitlist') === null,
        'no candidate fits the remaining gap -> null');
    $assert($seats($s1) === 4, 'session exactly full, never over');

    // The promotion mail went out inside the transaction.
    $mails = (int) Db::scalar(
        "SELECT COUNT(*) FROM mail_queue WHERE booking_id IN (?, ?) AND subject LIKE '%繰り上げ%'",
        [$b['booking_id'], $d['booking_id']]
    );
    $assert($mails === 2, 'one promotion mail queued per promotion');

    // --- 2. a candidate with an overlap acquired while waiting is skipped ----
    // P waits on s2 (seq 1) but - staged via a direct insert, the service
    // would refuse it - also holds a CONFIRMED booking at the same hour.
    // Q (seq 2) is clean. The freed seat must go to Q.
    $s2      = fixture_create_session($eventId, '2027-02-02 10:00:00', '2027-02-02 11:00:00', 1);
    $clash   = fixture_create_session($eventId, '2027-02-02 10:30:00', '2027-02-02 11:30:00', 5);
    $holder  = $service->book($s2, fixture_email('wl-holder'), 'Holder', 1);
    $pClash  = $service->book($clash, fixture_email('wl-p'), 'P', 1);
    $repo = new BookingRepository();
    $pWaiting = $repo->insert(
        referenceCode:   TokenService::newReferenceCode(),
        sessionId:       $s2,
        applicantId:     (int) Db::scalar('SELECT applicant_id FROM bookings WHERE id = ?', [$pClash['booking_id']]),
        email:           fixture_email('wl-p'),
        name:            'P',
        partySize:       1,
        status:          BookingStatus::Waitlisted,
        waitlistSeq:     1,
        cancelTokenHash: TokenService::hashToken('wl-p-probe'),
    );
    $q = $service->book($s2, fixture_email('wl-q'), 'Q', 1);
    $assert($q['status'] === BookingStatus::Waitlisted, 'Q queued behind P');

    (new CancellationService())->cancelById((int) $holder['booking_id'], 'test:waitlist');
    $promoted = $waitlist->promoteNextFitting($s2, 'test:waitlist');
    $assert($promoted !== null && (int) $promoted['id'] === (int) $q['booking_id'],
        'P (overlapping elsewhere) skipped, Q promoted');
    $assert($status($pWaiting) === 'waitlisted', 'P remains waitlisted rather than double-booked');

    // --- 3. auto_promote chains the same rule after a cancellation ----------
    // cap 5 filled by Y(5); queue E(3), F(3), G(2). Cancelling Y frees 5:
    // E(3) fits, F(3) no longer fits the gap of 2, G(2) does -> E and G.
    $s3 = fixture_create_session($eventId, '2027-02-03 10:00:00', '2027-02-03 11:00:00', 5);
    $y = $service->book($s3, fixture_email('wl-y'), 'Y', 5);
    $e = $service->book($s3, fixture_email('wl-e'), 'E', 3);
    $f = $service->book($s3, fixture_email('wl-f'), 'F', 3);
    $g = $service->book($s3, fixture_email('wl-g'), 'G', 2);

    $config = require dirname(__DIR__) . '/config/config.php';
    $config['waitlist']['auto_promote'] = true;
    Config::load($config);
    try {
        $result = (new CancellationService())->cancelById((int) $y['booking_id'], 'test:waitlist');
    } finally {
        $config['waitlist']['auto_promote'] = false;
        Config::load($config);
    }

    $assert($result['auto_promoted'] === 2, 'cancellation auto-promoted exactly two parties');
    $assert($status((int) $e['booking_id']) === 'confirmed', 'E (3, oldest) confirmed');
    $assert($status((int) $f['booking_id']) === 'waitlisted', 'F (3) does not fit the remaining 2 and waits');
    $assert($status((int) $g['booking_id']) === 'confirmed', 'G (2) fills the gap');
    $assert($seats($s3) === 5, 'seats add back up to capacity exactly');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "waitlist: all OK\n" : "waitlist: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
