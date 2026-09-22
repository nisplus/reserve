<?php

declare(strict_types=1);

/**
 * Bulk announcements to applicants (App\Service\BulkMailService).
 *
 * Two properties carry the weight here, because both fail silently and
 * neither can be taken back once the mail is out:
 *
 *   - scoping. A company account addressing "its" applicants must not reach
 *     another company's. The scope comes from the session, so what is tested
 *     here is that the query honours it even when asked for someone else's
 *     event.
 *   - deduplication. Someone holding three bookings is one person. Three
 *     copies of a cancellation notice is how an announcement becomes a
 *     complaint.
 *
 * Cancelled bookings are never included and waitlisted ones only on request,
 * which is the difference between telling the right people and telling
 * everyone who ever typed their address in.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Repository\EventRepository;
use App\Repository\MailQueueRepository;
use App\Service\BookingService;
use App\Service\BulkMailService;
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

/** @param array<int, array{email: string, name: string}> $rows */
$emails = static function (array $rows): array {
    $out = array_map(static fn (array $r): string => $r['email'], $rows);
    sort($out);
    return $out;
};

fixture_cleanup();
$bulk    = new BulkMailService();
$service = new BookingService();
$events  = new EventRepository();
$queue   = new MailQueueRepository();

$companyA = fixture_create_company('bulkA');
$companyB = fixture_create_company('bulkB');

try {
    // --- a fixture with every status and a repeat customer -------------------
    $eventA = $events->create($companyA, 'A event', null, null, 0, true);
    $slot1 = fixture_create_session($eventA, '2033-01-01 10:00:00', '2033-01-01 11:00:00', 2);
    $slot2 = fixture_create_session($eventA, '2033-01-02 10:00:00', '2033-01-02 11:00:00', 1);

    $eventB = $events->create($companyB, 'B event', null, null, 0, true);
    $slotB = fixture_create_session($eventB, '2033-01-03 10:00:00', '2033-01-03 11:00:00', 5);

    $repeat = fixture_email('bm-repeat');
    $service->book($slot1, $repeat, 'Repeat', 1, contactName: '常連 太郎');
    // Same address, a different event - one person, two bookings.
    $service->book($slotB, $repeat, 'Repeat', 1, contactName: '常連 太郎（新）');

    $service->book($slot2, fixture_email('bm-solo'), 'Solo', 1, contactName: 'ソロ 花子');

    // Fills slot1, then someone queues behind, then someone cancels.
    $service->book($slot1, fixture_email('bm-seated'), 'Seated', 1, contactName: '着席 次郎');
    $queued = $service->book($slot1, fixture_email('bm-queued'), 'Queued', 1, contactName: '待機 三郎');
    $gone = $service->book($slotB, fixture_email('bm-gone'), 'Gone', 1, contactName: '離脱 四郎');
    (new CancellationService())->cancelById((int) $gone['booking_id'], 'test:bulk');

    $assert((string) Db::scalar('SELECT status FROM bookings WHERE id = ?', [$queued['booking_id']])
        === 'waitlisted', 'the fixture has someone waitlisted');

    // --- who is addressed ----------------------------------------------------
    $all = $bulk->recipients(BulkMailService::SCOPE_ALL, 0, false);
    $assert(!in_array(fixture_email('bm-gone'), $emails($all), true),
        'a cancelled booking is never addressed - they already said they are not coming');
    $assert(!in_array(fixture_email('bm-queued'), $emails($all), true),
        'and a waitlisted one is not, unless asked for');

    $withWait = $bulk->recipients(BulkMailService::SCOPE_ALL, 0, true);
    $assert(in_array(fixture_email('bm-queued'), $emails($withWait), true),
        'the checkbox brings the waitlisted in');
    $assert(!in_array(fixture_email('bm-gone'), $emails($withWait), true),
        'but never the cancelled - that is not a checkbox');

    // One person, two bookings, one message.
    $assert(count(array_keys($emails($withWait), $repeat, true)) === 1,
        'an address holding two bookings is addressed once');
    // ...and named from the newer of them.
    $repeatRow = null;
    foreach ($withWait as $row) {
        if ($row['email'] === $repeat) {
            $repeatRow = $row;
        }
    }
    $assert($repeatRow !== null && $repeatRow['name'] === '常連 太郎（新）',
        'and named from their most recent booking');

    // --- scoping --------------------------------------------------------------
    $onlyA = $bulk->recipients(BulkMailService::SCOPE_EVENT, $eventA, true);
    $assert(!in_array(fixture_email('bm-gone'), $emails($onlyA), true)
        && in_array(fixture_email('bm-solo'), $emails($onlyA), true),
        'an event scope reaches that event only');

    $oneSlot = $bulk->recipients(BulkMailService::SCOPE_SESSION, $slot2, true);
    $assert($emails($oneSlot) === [fixture_email('bm-solo')],
        'a session scope reaches that session only - the case a cancelled day needs');

    // The company scope is the one that must not leak. Asking for another
    // company's event while scoped to your own returns nobody, rather than
    // that company's applicants.
    $leak = $bulk->recipients(BulkMailService::SCOPE_EVENT, $eventB, true, $companyA);
    $assert($leak === [],
        'a company scope asked for another company\'s event returns nobody');
    $scopedAll = $bulk->recipients(BulkMailService::SCOPE_ALL, 0, true, $companyA);
    $assert(!in_array(fixture_email('bm-gone'), $emails($scopedAll), true)
        && in_array(fixture_email('bm-solo'), $emails($scopedAll), true),
        'and an unscoped request from a company account still only reaches its own');

    // --- the message ----------------------------------------------------------
    $context = $bulk->context(BulkMailService::SCOPE_SESSION, $slot2);
    $assert($context !== null && str_contains($context['title'], 'A event')
        && str_contains($context['when'], '2033-01-02'),
        'a session announcement knows which session it is about');
    $composed = $bulk->compose('中止します。', $context);
    $assert(str_contains($composed, 'A event') && str_contains($composed, '中止します。'),
        'and the body carries that context so the reader knows which booking');
    $assert($bulk->compose('全体連絡です。', null) === '全体連絡です。',
        'a whole-site announcement is about no one event, so it gets no header');

    // --- queueing --------------------------------------------------------------
    $before = (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE category = 'bulk'");
    $queuedCount = $bulk->enqueue($oneSlot, 'お知らせ', $composed);
    $assert($queuedCount === 1, 'one recipient, one message queued');
    $assert((int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE category = 'bulk'") === $before + 1,
        'and it is marked as bulk');
    $row = Db::selectOne(
        "SELECT to_email, to_name, booking_id, category FROM mail_queue
          WHERE category = 'bulk' ORDER BY id DESC LIMIT 1"
    );
    $assert($row['to_email'] === fixture_email('bm-solo') && $row['to_name'] === 'ソロ 花子',
        'addressed to the person, by the name they gave');
    $assert($row['booking_id'] === null,
        'and hung off no booking - it is about one, not from one');
    $assert($bulk->enqueue([], 'お知らせ', '本文') === 0, 'an empty list queues nothing');

    // --- the inline send does not pick up a campaign --------------------------
    // This is why the category exists: the request that just took a booking
    // must not spend itself sending other people's mail.
    // A limit large enough to hold the whole pending queue, so the three
    // selections can be compared rather than three arbitrary prefixes of it.
    $limit = $queue->countPending() + 10;
    $bulkIds = $queue->pendingIds($limit, MailQueueRepository::BULK);
    $transactionalIds = $queue->pendingIds($limit, MailQueueRepository::TRANSACTIONAL);
    $allIds = $queue->pendingIds($limit);

    $assert($bulkIds !== [] && $transactionalIds !== [],
        'the queue holds both kinds right now');
    $assert(array_intersect($bulkIds, $transactionalIds) === [],
        'and the two selections do not overlap');
    $assert(count($allIds) === count($bulkIds) + count($transactionalIds),
        'while an unfiltered drain - cron, or the admin button - still sees both');
} finally {
    Db::execute("DELETE FROM mail_queue WHERE category = 'bulk' AND to_email LIKE ?", ['%' . FIXTURE_EMAIL_DOMAIN]);
    fixture_cleanup();
}

echo $failures === 0 ? "bulk mail: all OK\n" : "bulk mail: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
