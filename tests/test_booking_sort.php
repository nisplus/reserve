<?php

declare(strict_types=1);

/**
 * The admin booking list's sort orders (App\Domain\BookingSort).
 *
 * The schedule order is the one worth pinning down: company, then the
 * company's programmes in display order, then each programme's sessions in
 * time order, then confirmed before waitlisted before cancelled. Five keys
 * means five chances to get the precedence wrong, so the fixture below is
 * built so that every key disagrees with the others - sorting by any one of
 * them alone produces a different sequence from the right answer.
 *
 * It is also the only ORDER BY in the codebase that is interpolated rather
 * than bound, so the enum's total-function behaviour on unknown input is
 * asserted here too.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\BookingSort;
use App\Repository\BookingRepository;
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

$repo    = new BookingRepository();
$events  = new EventRepository();
$service = new BookingService();

try {
    // --- the enum ------------------------------------------------------------
    $assert(BookingSort::fromRequest('schedule') === BookingSort::Schedule
        && BookingSort::fromRequest('newest') === BookingSort::Newest,
        'the two orders are reachable by name');
    $assert(BookingSort::fromRequest('') === BookingSort::Newest
        && BookingSort::fromRequest('nonsense') === BookingSort::Newest
        && BookingSort::fromRequest('b.id; DROP TABLE bookings') === BookingSort::Newest,
        'anything unrecognised falls back to the default rather than reaching the query');
    $assert(array_keys(BookingSort::options()) === ['newest', 'schedule'],
        'both orders are offered to the select box');

    foreach (BookingSort::cases() as $case) {
        $assert(str_ends_with($case->orderBy(), 'b.id')
            || str_ends_with($case->orderBy(), 'b.id DESC'),
            "{$case->value} breaks ties on b.id, so paging cannot drop or repeat a row");
    }

    // --- a fixture where every sort key disagrees ----------------------------
    // Two companies. The SECOND one created sorts first by nothing except its
    // id, so company order is only satisfied by c.id.
    $companyA = fixture_create_company('sortA');
    $companyB = fixture_create_company('sortB');

    // Within company A, the event created FIRST is displayed SECOND, so a sort
    // that used e.id instead of e.sort_order would get this pair backwards.
    $late  = $events->create($companyA, 'A-displayed-second', null, null, 9, true);
    $early = $events->create($companyA, 'A-displayed-first', null, null, 1, true);
    $bEvent = $events->create($companyB, 'B-only', null, null, 0, true);

    // Within the first-displayed event, the session created first runs LAST,
    // so session order is only satisfied by starts_at.
    $pm = fixture_create_session($early, '2030-01-01 15:00:00', '2030-01-01 16:00:00', 5);
    $am = fixture_create_session($early, '2030-01-01 09:00:00', '2030-01-01 10:00:00', 1);
    $lateSlot = fixture_create_session($late, '2030-01-02 09:00:00', '2030-01-02 10:00:00', 5);
    $bSlot = fixture_create_session($bEvent, '2030-01-03 09:00:00', '2030-01-03 10:00:00', 5);

    // On the morning slot (capacity 1): confirmed, then a queue, then someone
    // who cancelled. Created in an order that puts the cancelled row FIRST by
    // created_at, so status order is only satisfied by the CASE.
    $gone = $service->book($am, fixture_email('so-gone'), 'Gone', 1);
    (new CancellationService())->cancelById((int) $gone['booking_id'], 'test:sort');
    $seated = $service->book($am, fixture_email('so-seated'), 'Seated', 1);
    $queued = $service->book($am, fixture_email('so-queued'), 'Queued', 1);

    $afternoon = $service->book($pm, fixture_email('so-pm'), 'Afternoon', 1);
    $lateEvent = $service->book($lateSlot, fixture_email('so-late'), 'LateEvent', 1);
    $otherCo   = $service->book($bSlot, fixture_email('so-b'), 'OtherCompany', 1);

    $filters = ['company_id' => 0, 'event_id' => 0, 'session_id' => 0, 'status' => '', 'email' => 'CT-TEST'];
    // The fixture's addresses share a domain; filter on that instead of a name.
    $filters['email'] = FIXTURE_EMAIL_DOMAIN;

    $namesIn = static function (BookingSort $sort) use ($repo, $filters): array {
        return array_map(
            static fn (array $r): string => (string) $r['name'],
            $repo->searchForAdmin($filters, 100, 0, $sort)
        );
    };

    // --- newest first --------------------------------------------------------
    $newest = $namesIn(BookingSort::Newest);
    $assert($newest === ['OtherCompany', 'LateEvent', 'Afternoon', 'Queued', 'Seated', 'Gone'],
        'the default order is newest application first');

    // --- the schedule order --------------------------------------------------
    $schedule = $namesIn(BookingSort::Schedule);
    $expected = [
        // company A, its first-displayed event, its 09:00 session
        'Seated',       // confirmed
        'Queued',       // waitlisted
        'Gone',         // cancelled
        'Afternoon',    // same event, 15:00 session
        'LateEvent',    // company A, second-displayed event
        'OtherCompany', // company B
    ];
    $assert($schedule === $expected, 'the schedule order walks company, programme, session, status');

    // Each key is checked on its own, so a failure above says which one broke.
    $pos = static fn (string $name): int => (int) array_search($name, $schedule, true);
    $assert($pos('LateEvent') < $pos('OtherCompany'),
        'company comes first - both of company A before company B');
    $assert($pos('Afternoon') < $pos('LateEvent'),
        'then the programme display order, not the order they were created');
    $assert($pos('Seated') < $pos('Afternoon'),
        'then the session start time, not the order they were created');
    $assert($pos('Seated') < $pos('Queued') && $pos('Queued') < $pos('Gone'),
        'then confirmed, waitlisted, cancelled - not the order they were booked');

    // The queue keeps its own order inside the waitlisted group.
    $second = $service->book($am, fixture_email('so-queued2'), 'Queued2', 1);
    $assert((int) Db::scalar('SELECT waitlist_seq FROM bookings WHERE id = ?', [$second['booking_id']]) === 2,
        'a second applicant queues behind the first');
    $withQueue = $namesIn(BookingSort::Schedule);
    $qpos = static fn (string $name): int => (int) array_search($name, $withQueue, true);
    $assert($qpos('Queued') < $qpos('Queued2'),
        'and the waitlisted rows stay in queue order within their group');

    // --- paging is stable ----------------------------------------------------
    // Every row exactly once across two pages, in the same sequence as one
    // unpaged read - the property the b.id tiebreak exists for.
    $all = $namesIn(BookingSort::Schedule);
    $paged = [];
    for ($offset = 0; $offset < count($all); $offset += 3) {
        $paged = array_merge($paged, array_map(
            static fn (array $r): string => (string) $r['name'],
            $repo->searchForAdmin($filters, 3, $offset, BookingSort::Schedule)
        ));
    }
    $assert($paged === $all,
        'paging through the schedule order reproduces it exactly, with no row dropped or repeated');

    // --- filters still apply -------------------------------------------------
    $confirmedOnly = $filters;
    $confirmedOnly['status'] = 'confirmed';
    $rows = $repo->searchForAdmin($confirmedOnly, 100, 0, BookingSort::Schedule);
    $assert($rows !== [] && array_reduce(
        $rows,
        static fn (bool $carry, array $r): bool => $carry && $r['status'] === 'confirmed',
        true
    ), 'the sort does not disturb the filters it is combined with');
} finally {
    fixture_cleanup();
}

echo $failures === 0 ? "booking sort: all OK\n" : "booking sort: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
