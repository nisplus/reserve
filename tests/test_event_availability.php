<?php

declare(strict_types=1);

/**
 * The per-event availability summary shown on the admin programme list and the
 * public catalogue (partials/event_availability.php).
 *
 * The two lists are fed by different queries - listForAdmin and
 * publishedCatalogue - so the assertions below check the two agree on the same
 * event. They had better: they render the same partial, and a badge that says
 * 全回満席 to the office and 空き 3 名分 to an applicant is worse than either
 * one being wrong on its own.
 *
 * The state worth the most care is an event whose sessions all have queues.
 * Seats exist, so 全回満席 is false; none can be taken, so 空き N 名分 is a
 * promise the booking screen would refuse. It has to read キャンセル待ち.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Core\View;
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
$company = fixture_create_company('availability');
$events  = new EventRepository();
$service = new BookingService();

/** The badge text an event's row would render. */
$badge = static function (array $row, string $sessionCountKey): string {
    $html = View::renderPartial('partials/event_availability', [
        'needsBooking' => (int) $row['booking_required'] === 1,
        'sessionCount' => (int) $row[$sessionCountKey],
        'seatsLeft'    => (int) $row['seats_left'],
        'waitingCount' => (int) $row['waiting_count'],
    ]);
    return trim(strip_tags($html));
};

try {
    // --- four events, one per state ------------------------------------------
    $open = $events->create($company, 'A-open', null, null, 0, true);
    fixture_create_session($open, '2031-01-01 10:00:00', '2031-01-01 11:00:00', 5);
    fixture_create_session($open, '2031-01-01 13:00:00', '2031-01-01 14:00:00', 5);

    $full = $events->create($company, 'B-full', null, null, 0, true);
    $fullSlot = fixture_create_session($full, '2031-01-02 10:00:00', '2031-01-02 11:00:00', 1);
    $service->book($fullSlot, fixture_email('av-full'), 'Full', 1);

    $queued = $events->create($company, 'C-queued', null, null, 0, true);
    $queuedSlot = fixture_create_session($queued, '2031-01-03 10:00:00', '2031-01-03 11:00:00', 2);
    $holder = $service->book($queuedSlot, fixture_email('av-holder'), 'Holder', 2);
    $service->book($queuedSlot, fixture_email('av-wait'), 'Waiting', 1);

    $bare = $events->create($company, 'D-no-sessions', null, null, 0, true);
    $free = $events->create($company, 'E-no-booking', null, null, 0, true, bookingRequired: false);
    fixture_create_session($free, '2031-01-05 10:00:00', '2031-01-05 11:00:00', 5);

    $rows = [];
    foreach ($events->listForAdmin($company) as $row) {
        $rows[(int) $row['id']] = $row;
    }

    $assert(count($rows) === 5, 'all five events are listed');

    $assert((int) $rows[$open]['seats_left'] === 10
        && (int) $rows[$open]['waiting_count'] === 0,
        'an untouched event offers every seat of every session');
    $assert($badge($rows[$open], 'open_session_count') === '空き 10 名分', 'and reads 空き N 名分');

    $assert((int) $rows[$full]['seats_left'] === 0,
        'a filled session offers none');
    $assert($badge($rows[$full], 'open_session_count') === '全回満席', 'and reads 全回満席');

    // The one that matters: two seats free, one person waiting for them.
    (new CancellationService())->cancelById((int) $holder['booking_id'], 'test:availability');
    $rows = [];
    foreach ($events->listForAdmin($company) as $row) {
        $rows[(int) $row['id']] = $row;
    }
    $physicallyFree = (int) Db::scalar(
        'SELECT capacity - confirmed_seats FROM event_sessions WHERE id = ?',
        [$queuedSlot]
    );
    $assert($physicallyFree === 2, 'the cancellation freed both seats');
    $assert((int) $rows[$queued]['seats_left'] === 0
        && (int) $rows[$queued]['waiting_count'] === 1,
        'but the event offers none of them, because a queue holds them');
    $assert($badge($rows[$queued], 'open_session_count') === 'キャンセル待ち',
        'so the badge reads キャンセル待ち, neither 全回満席 nor 空き 2 名分');

    $assert($badge($rows[$bare], 'open_session_count') === '受付前',
        'an event with no sessions reads 受付前');
    $assert($badge($rows[$free], 'open_session_count') === '予約不要',
        'a 予約不要 event reads 予約不要 whatever its sessions hold');

    // --- a closed session is not availability --------------------------------
    Db::execute("UPDATE event_sessions SET status = 'closed' WHERE event_id = ?", [$open]);
    $rows = [];
    foreach ($events->listForAdmin($company) as $row) {
        $rows[(int) $row['id']] = $row;
    }
    $assert((int) $rows[$open]['session_count'] === 2
        && (int) $rows[$open]['open_session_count'] === 0,
        'closing the sessions leaves the 開催回 count alone and empties the open count');
    $assert($badge($rows[$open], 'open_session_count') === '受付前',
        'and an event whose sessions are all closed reads 受付前, not 全回満席');

    // --- the two lists agree --------------------------------------------------
    // publishedCatalogue only sees published rows, so publish the fixture for
    // this comparison and hide it again.
    Db::execute('UPDATE companies SET is_published = 1 WHERE id = ?', [$company]);
    $catalogue = [];
    foreach ($events->publishedCatalogue() as $row) {
        $catalogue[(int) $row['id']] = $row;
    }
    Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$company]);

    $compared = 0;
    foreach ([$full, $queued, $bare, $free] as $id) {
        if (!isset($catalogue[$id])) {
            continue;
        }
        $compared++;
        $assert(
            $badge($catalogue[$id], 'session_count') === $badge($rows[$id], 'open_session_count'),
            "the office and an applicant read the same badge for event {$id}"
        );
    }
    $assert($compared > 0, 'the comparison actually ran against the catalogue');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "event availability: all OK\n" : "event availability: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
