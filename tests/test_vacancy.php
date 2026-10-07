<?php

declare(strict_types=1);

/**
 * Day-of availability (App\Service\VacancyService, App\Domain\VacancyLevel).
 *
 * The feature exists because the booking system cannot answer the question it
 * answers: walk-up tickets are paper, so confirmed_seats is wrong on the day.
 * Everything here therefore turns on reports that people typed in, and the
 * properties worth asserting are about which report wins and how old it is.
 *
 *   - the newest report for a key is the current one, and nothing else is
 *   - a session with no report of its own falls back to the booth's state,
 *     flagged as a different claim rather than passed off as its own
 *   - a report nobody has refreshed in ninety minutes says so, because a stale
 *     ◎ on a wall sends people to a booth that filled up an hour ago
 *   - 予約不要 is a walk-up booth whatever sessions it has: a current status
 *     and no per-session screen, because nothing can be reserved for a slot
 *     there and a per-slot ticket count would be a number about nothing
 *
 * And one that is structural: this writes to vacancy_reports and nothing else.
 *
 * The fixture day is TODAY rather than a far-future date, because a report
 * carrying no session belongs to the day it was made - so a report cannot be
 * both findable on the day under test and old enough to have gone stale
 * unless that day is today. Every assertion is scoped to a fixture company
 * for the same reason: today's real rows are in the way otherwise.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/_fixture.php';

use App\Core\Db;
use App\Domain\VacancyLevel;
use App\Repository\EventRepository;
use App\Repository\VacancyRepository;
use App\Service\VacancyService;

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
Db::execute('DELETE FROM vacancy_reports WHERE reported_by = ?', ['test:vacancy']);

$events = new EventRepository();
$repo = new VacancyRepository();
$service = new VacancyService();

$DAY  = date('Y-m-d');
$NEXT = date('Y-m-d', strtotime('+1 day'));
$FAR  = date('Y-m-d', strtotime('+40 days'));

$ago = static fn (int $minutes): string => date('Y-m-d H:i:s', time() - $minutes * 60);

/** @return array<int, array<string, mixed>> the day's rows for one company, keyed by event id */
$rowsFor = static function (int $companyId, ?string $day = null) use ($service, $DAY): array {
    $out = [];
    foreach ($service->forEvents($day ?? $DAY, $companyId, false) as $row) {
        $out[(int) $row['id']] = $row;
    }
    return $out;
};

/** @return array<int, int> event id => how many rows it has */
$idsOf = static fn (array $rows): array => array_map(
    static fn (array $r): int => (int) $r['id'],
    $rows
);

try {
    $companyA = fixture_create_company('vacA');
    $companyB = fixture_create_company('vacB');

    $tour  = $events->create($companyA, 'A社 見学', null, 'A棟 受付', 0, true);
    $build = $events->create($companyA, 'A社 体験', null, null, 0, true);
    $other = $events->create($companyB, 'B社 説明', null, null, 0, true);

    $s1 = fixture_create_session($tour, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    $s2 = fixture_create_session($tour, $DAY . ' 13:00:00', $DAY . ' 14:00:00', 20);
    fixture_create_session($build, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    fixture_create_session($other, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    // Another day, to prove the date filter does something.
    fixture_create_session($tour, $NEXT . ' 10:00:00', $NEXT . ' 11:00:00', 20);

    // --- the marks -----------------------------------------------------------
    $assert(VacancyLevel::Open->mark() === '◎' && VacancyLevel::None->mark() === '✕',
        'the four marks carry the meaning without needing colour');
    $assert(VacancyLevel::reconcile(VacancyLevel::Open, 0) === VacancyLevel::None,
        'a remaining count of zero overrides ◎ - the two would contradict each other on screen');
    $assert(VacancyLevel::reconcile(VacancyLevel::Open, 3) === VacancyLevel::Open,
        'any other count is left to the operator; 3 tickets can be ◎ or △');
    $assert(VacancyLevel::reconcile(VacancyLevel::Few, null) === VacancyLevel::Few,
        'and a report with no count at all is untouched');

    // --- nothing reported yet ------------------------------------------------
    $rows = $rowsFor($companyA);
    $assert(count($rows) === 2, 'every booth running that day is listed, reported or not');
    $assert($rows[$tour]['report'] === null,
        'with no report the entry is null - the page shows a dash, not an empty space');

    // --- newest wins ---------------------------------------------------------
    $repo->add($tour, null, 'open', null, null, 'test:vacancy', $ago(30));
    $repo->add($tour, null, 'few', 4, null, 'test:vacancy', $ago(5));

    $byId = $rowsFor($companyA);
    $assert($byId[$tour]['report']['level'] === VacancyLevel::Few,
        'the newest report is the current one');
    $assert($byId[$tour]['report']['remaining'] === 4, 'and brings its ticket count with it');
    $assert($byId[$build]['report'] === null, 'a report never leaks to another booth');

    // --- age -----------------------------------------------------------------
    $assert($byId[$tour]['report']['is_stale'] === false, 'five minutes old is current');

    $repo->add($other, null, 'ample', null, null, 'test:vacancy', $ago(VacancyService::STALE_MINUTES + 1));
    $otherRow = $rowsFor($companyB)[$other];
    $assert($otherRow['report']['is_stale'] === true,
        'past ninety minutes it is marked stale - the dangerous failure is a stale ◎ on a wall');
    $assert($otherRow['report']['age_minutes'] >= VacancyService::STALE_MINUTES,
        'and the age is carried so the screen can say how long');

    // --- a current status belongs to the day it was entered -------------------
    // There is no session to take a date from, so the day it was typed is the
    // only day it describes. reset_vacancy.php already draws the line here;
    // without it, a booth practised on last week still reads as today's ◎.
    $assert(!isset($rowsFor($companyA, $NEXT)[$tour]['report'])
        || $rowsFor($companyA, $NEXT)[$tour]['report'] === null,
        "today's current status is not served up as tomorrow's");

    // --- per session, and the fallback --------------------------------------
    $repo->add($tour, $s1, 'none', 0, null, 'test:vacancy', $ago(10));

    $sessions = [];
    foreach ($service->forSessions($DAY, $companyA, false, null, false) as $row) {
        $sessions[(int) $row['id']] = $row;
    }

    $assert($sessions[$s1]['report']['level'] === VacancyLevel::None,
        'a session shows its own report when it has one');
    $assert($sessions[$s1]['fallback'] === null,
        'and then has no need of the fallback');
    $assert($sessions[$s2]['report'] === null && $sessions[$s2]['fallback'] !== null,
        'a session with no report of its own falls back to the booth');
    $assert($sessions[$s2]['fallback']['level'] === VacancyLevel::Few,
        'showing what the booth last said, which is a different claim and is labelled as one');

    // --- the date filter -----------------------------------------------------
    $assert($idsOf($service->forEvents($NEXT, $companyA, false)) === [$tour],
        'the next day lists the booth that runs then, and only that one');
    $assert($service->forEvents($FAR, $companyB, false) === [],
        'and a day with nothing on returns nothing');

    // --- company scope -------------------------------------------------------
    $ids = $idsOf($service->forEvents($DAY, $companyA, false));
    $assert(in_array($tour, $ids, true) && !in_array($other, $ids, true),
        'a company account sees only its own booths');

    // --- finished sessions are dropped from the public view ------------------
    $past = fixture_create_session($build, $DAY . ' 00:00:00', $DAY . ' 00:30:00', 20);
    $upcomingIds = $idsOf($service->forSessions($DAY, $companyA, true, null, false));
    $allIds      = $idsOf($service->forSessions($DAY, $companyA, false, null, false));
    $assert(!in_array($past, $upcomingIds, true),
        'a session that already finished is not shown to visitors');
    $assert(in_array($past, $allIds, true),
        'but the office can still ask for the whole day');

    // --- what it writes to ----------------------------------------------------
    // The claim the whole feature rests on: the booking tables are read only.
    $seatsBefore = (int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$s1]);
    $bookingsBefore = (int) Db::scalar('SELECT COUNT(*) FROM bookings');
    $repo->add($tour, $s2, 'ample', 9, null, 'test:vacancy');
    $assert((int) Db::scalar('SELECT confirmed_seats FROM event_sessions WHERE id = ?', [$s1]) === $seatsBefore,
        'writing a report does not touch confirmed_seats');
    $assert((int) Db::scalar('SELECT COUNT(*) FROM bookings') === $bookingsBefore,
        'nor bookings - this feature cannot disturb the booking system');

    // --- a booth with no sessions at all --------------------------------------
    // The design always said these take a "current status" and nothing else.
    // An inner join on event_sessions quietly dropped them, so the one kind of
    // event that can ONLY be reported this way could not be reported at all.
    $walkIn = $events->create($companyA, 'A社 随時受付の展示', null, 'ロビー', 0, true);

    $ids = $idsOf($service->forEvents($DAY, $companyA, false));
    $assert(in_array($walkIn, $ids, true),
        'a booth with no sessions is listed, because a current status is all it can have');

    $rows = $rowsFor($companyA);
    $assert($rows[$walkIn]['is_walk_in'] === true && $rows[$tour]['is_walk_in'] === false,
        'and is_walk_in tells the screen which rows have no per-session form');
    $assert((int) $rows[$walkIn]['session_count'] === 0 && (int) $rows[$tour]['session_count'] === 2,
        'session_count still counts what the day actually holds');

    // An event whose sessions are all on other days is a different case: it is
    // not running today, so there is nothing to say about it today.
    $otherDay = $events->create($companyA, 'A社 来月だけ', null, null, 0, true);
    fixture_create_session($otherDay, $FAR . ' 10:00:00', $FAR . ' 11:00:00', 10);
    $assert(!in_array($otherDay, $idsOf($service->forEvents($DAY, $companyA, false)), true),
        'a booth running only on other days stays off this day');

    $repo->add($walkIn, null, 'open', null, null, 'test:vacancy');
    $rows = $rowsFor($companyA);
    $assert($rows[$walkIn]['report'] !== null
        && $rows[$walkIn]['report']['level'] === VacancyLevel::Open,
        'and its current status is published like any other');

    // --- 予約不要 is a walk-up booth even with sessions -------------------------
    // Reported as "treat 予約不要 as a same-day booth whether or not sessions
    // are registered". Sessions on such a booth say when staff are there, not
    // what can be reserved, so there is no per-slot number to report.
    $standing = $events->create($companyA, 'A社 予約不要の工房', null, '工房', 0, true, false);
    $sFree = fixture_create_session($standing, $DAY . ' 10:00:00', $DAY . ' 16:00:00', 0);

    $rows = $rowsFor($companyA);
    $assert(isset($rows[$standing]),
        '予約不要 with sessions is listed on the day like any other booth');
    $assert($rows[$standing]['is_walk_in'] === true,
        'but it is a walk-up booth: a current status, and no per-session screen');
    $assert((int) $rows[$standing]['session_count'] === 1,
        'the sessions are still counted - the rule is 予約不要, not "has no sessions"');

    $assert(!in_array($sFree, $idsOf($service->forSessions($DAY, $companyA, false, null, false)), true),
        'and its sessions stay out of the per-session views, which is the same decision');

    $repo->add($standing, null, 'ample', null, null, 'test:vacancy');
    $assert($rowsFor($companyA)[$standing]['report']['level'] === VacancyLevel::Ample,
        'a current status registered against it comes back out');

    // It is a walk-up booth every day, not only on the days its sessions run.
    $assert(in_array($standing, $idsOf($service->forEvents($FAR, $companyA, false)), true),
        '予約不要 is listed whatever day is being looked at - it has no date to be off');

    // --- finding the day that does have sessions ------------------------------
    // Every day but the festival's own lists walk-up booths and nothing else,
    // which reads as a broken screen. The input screen offers these instead.
    $days = $service->sessionDaysNear($FAR, $companyA, 3);
    $found = array_column($days, 'date');
    $assert(in_array($DAY, $found, true) && in_array($NEXT, $found, true),
        'the days that do have sessions are offered, nearest first');
    $assert($found === array_values(array_unique($found))
        && $found[0] <= $found[count($found) - 1],
        'listed once each and in date order, which is how they are read');
    foreach ($days as $day) {
        if ($day['date'] === $NEXT) {
            $assert($day['sessions'] === 1, 'each with how many sessions are on, so the right day is obvious');
        }
    }

    // --- the signage rehearsal -------------------------------------------------
    // Checking the wall display must not require the day it has to be right on.
    $before = (int) Db::scalar('SELECT COUNT(*) FROM vacancy_reports');
    $sample = $service->sampleRows(8);
    $assert(count($sample) === 8, 'the rehearsal builds as many rows as asked for');
    $assert((int) Db::scalar('SELECT COUNT(*) FROM vacancy_reports') === $before,
        'and writes none of them - a rehearsal must not become data');

    $marks = [];
    $stale = 0;
    foreach ($sample as $row) {
        $marks[$row['report']['level']->value] = true;
        $stale += $row['report']['is_stale'] ? 1 : 0;
    }
    $assert(count($marks) === 4, 'all four marks appear, so every colour can be judged');
    $assert($stale > 0, 'and one row is old enough to grey out, which is the case you cannot stage');

    // --- the history the office reads back ------------------------------------
    $recent = $repo->recent(5, $companyA);
    $assert($recent !== [] && (string) $recent[0]['reported_by'] === 'test:vacancy',
        'who entered what is kept, because the day runs on relayed messages');
} finally {
    Db::execute('DELETE FROM vacancy_reports WHERE reported_by = ?', ['test:vacancy']);
    fixture_cleanup();
}

echo $failures === 0 ? "vacancy: all OK\n" : "vacancy: {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
