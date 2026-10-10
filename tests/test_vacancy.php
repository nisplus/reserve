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
 *   - only 予約不要 is here at all. A programme that takes bookings already
 *     has a seat count the booking system can answer with, and a second
 *     hand-typed number beside it would only disagree with it
 *   - and a 予約不要 booth WITH rounds registered gets them from
 *     event_sessions: taking no bookings does not mean having no rounds
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
use App\Exception\NotFoundException;
use App\Http\Controller\Admin\VacancyController as AdminVacancyController;
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

    // 予約不要 throughout: that is the only kind this board carries.
    $tour  = $events->create($companyA, 'A社 見学', null, 'A棟 受付', 0, true, false);
    $build = $events->create($companyA, 'A社 体験', null, null, 0, true, false);
    $other = $events->create($companyB, 'B社 説明', null, null, 0, true, false);

    $s1 = fixture_create_session($tour, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    $s2 = fixture_create_session($tour, $DAY . ' 13:00:00', $DAY . ' 14:00:00', 20);
    fixture_create_session($build, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    fixture_create_session($other, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 20);
    // Another day, to prove the date filter does something - and three of
    // them, because a round that has not happened yet is the only kind the
    // board shows, and a test run at 16:00 must still have some.
    fixture_create_session($tour, $NEXT . ' 10:00:00', $NEXT . ' 11:00:00', 20);
    fixture_create_session($tour, $NEXT . ' 13:00:00', $NEXT . ' 14:00:00', 20);
    fixture_create_session($tour, $NEXT . ' 15:00:00', $NEXT . ' 16:00:00', 20);

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

    // --- what the date decides ------------------------------------------------
    // Not whether a booth is listed - a 予約不要 booth has no date of its own
    // and is always worth asking about - but whether it has rounds that day.
    $nextDay = $rowsFor($companyA, $NEXT);
    $assert((int) $nextDay[$tour]['session_count'] === 3 && $nextDay[$tour]['is_walk_in'] === false,
        'the booth with a round the next day offers its per-session screen then');
    $assert($nextDay[$build]['is_walk_in'] === true,
        'and the one without takes the marks and nothing else');

    $far = $rowsFor($companyB, $FAR);
    $assert(isset($far[$other]) && $far[$other]['is_walk_in'] === true,
        'on a day with no rounds at all the booths are still there, with the marks only');

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
    $walkIn = $events->create($companyA, 'A社 随時受付の展示', null, 'ロビー', 0, true, false);

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
    $otherDay = $events->create($companyA, 'A社 来月だけ', null, null, 0, true, false);
    fixture_create_session($otherDay, $FAR . ' 10:00:00', $FAR . ' 11:00:00', 10);
    $assert($rowsFor($companyA)[$otherDay]['is_walk_in'] === true,
        'a booth whose rounds are all on other days takes the marks and nothing else today');

    $repo->add($walkIn, null, 'open', null, null, 'test:vacancy');
    $rows = $rowsFor($companyA);
    $assert($rows[$walkIn]['report'] !== null
        && $rows[$walkIn]['report']['level'] === VacancyLevel::Open,
        'and its current status is published like any other');

    // --- 予約不要 with rounds registered ----------------------------------------
    // Taking no bookings does not mean having no rounds: a workshop can run
    // 10:00 / 13:00 / 15:00 and hand its tickets out on the door. Where there
    // are rounds, they come from event_sessions like anything else.
    $standing = $events->create($companyA, 'A社 予約不要の工房', null, '工房', 0, true, false);
    $f1 = fixture_create_session($standing, $DAY . ' 10:00:00', $DAY . ' 11:00:00', 0);
    $f2 = fixture_create_session($standing, $DAY . ' 13:00:00', $DAY . ' 14:00:00', 0);

    $rows = $rowsFor($companyA);
    $assert(isset($rows[$standing]), '予約不要 with rounds is listed on the day');
    $assert($rows[$standing]['is_walk_in'] === false && (int) $rows[$standing]['session_count'] === 2,
        'and it has a per-session screen, because the rounds exist in event_sessions');

    $freeSessions = $idsOf($service->forSessions($DAY, $companyA, false, $standing, false));
    $assert($freeSessions === [$f1, $f2],
        'its rounds come back from the database, in time order, like any other');

    $repo->add($standing, $f2, 'few', 3, null, 'test:vacancy');
    $byFree = [];
    foreach ($service->forSessions($DAY, $companyA, false, $standing, false) as $row) {
        $byFree[(int) $row['id']] = $row;
    }
    $assert($byFree[$f2]['report']['level'] === VacancyLevel::Few,
        'a round of one can be reported on by itself');
    $assert($byFree[$f1]['report'] === null,
        'without that becoming a claim about the round beside it');

    // A 予約不要 booth is listed on every day: it has no date of its own, so
    // there is no day it is not worth asking about.
    $assert(in_array($standing, $idsOf($service->forEvents($FAR, $companyA, false)), true),
        '予約不要 is listed whatever day is being looked at');
    $assert($rowsFor($companyA, $FAR)[$standing]['is_walk_in'] === true,
        'but only with the marks on a day none of its rounds run');

    // --- 予約必要: the booking system answers for itself --------------------------
    // It carries a seat count, so a round nobody has reported on is not a
    // blank - it is whatever the seats say. Two steps only: the count knows
    // whether anyone can still get in, not whether the queue is out the door.
    $assert(VacancyLevel::fromSeats(5, 0) === VacancyLevel::Few,
        'seats free and nobody queueing is △ - a way in, and no claim beyond that');
    $assert(VacancyLevel::fromSeats(0, 0) === VacancyLevel::None,
        'no seats is ✕');
    $assert(VacancyLevel::fromSeats(5, 1) === VacancyLevel::None,
        'and seats free with somebody queueing is ✕ too, because the queue owns them');

    $booked = $events->create($companyA, 'A社 要予約の見学', null, null, 0, true, true);
    $full  = fixture_create_session($booked, $DAY . ' 23:30:00', $DAY . ' 23:45:00', 20);
    $spare = fixture_create_session($booked, $DAY . ' 23:50:00', $DAY . ' 23:59:00', 20);
    Db::execute('UPDATE event_sessions SET confirmed_seats = capacity WHERE id = ?', [$full]);

    $rounds = [];
    foreach ($service->forSessions($DAY, $companyA, false, $booked, false) as $row) {
        $rounds[(int) $row['id']] = $row;
    }
    $assert(isset($rounds[$full], $rounds[$spare]),
        'a programme that takes bookings is on the board now, round by round');
    $assert($rounds[$full]['report']['level'] === VacancyLevel::None
        && $rounds[$full]['is_system'] === true,
        'and a full round says ✕ without anybody typing anything');
    $assert($rounds[$spare]['report']['level'] === VacancyLevel::Few,
        'while one with seats says △');

    // The "now" tab is one row per programme, so it answers across the
    // rounds still to come. Without it that tab - the one a visitor is
    // likeliest to open - said 未報告 against every bookable programme.
    $byEvent = [];
    foreach ($service->forEvents($DAY, $companyA, false) as $row) {
        $byEvent[(int) $row['id']] = $row;
    }
    $assert(($byEvent[$booked]['report']['level'] ?? null) === VacancyLevel::Few,
        'and the programme itself says △ while any round of it can still be got into');

    // A person beats the seat count: they can see the queue in the corridor
    // and the database cannot.
    $repo->add($booked, $full, 'open', null, null, 'test:vacancy');
    $rounds = [];
    foreach ($service->forSessions($DAY, $companyA, false, $booked, false) as $row) {
        $rounds[(int) $row['id']] = $row;
    }
    $assert($rounds[$full]['report']['level'] === VacancyLevel::Open
        && $rounds[$full]['is_system'] === false,
        'somebody at the booth overrules the seat count');

    // But only while they are still speaking. A report nobody has refreshed
    // in ninety minutes is a trace of a person, not a person; the seat count
    // is current by construction, so past that line it takes over again.
    Db::execute(
        'UPDATE vacancy_reports SET reported_at = ? WHERE session_id = ?',
        [date('Y-m-d H:i:s', time() - (VacancyService::STALE_MINUTES + 10) * 60), $full]
    );
    $rounds = [];
    foreach ($service->forSessions($DAY, $companyA, false, $booked, false) as $row) {
        $rounds[(int) $row['id']] = $row;
    }
    $assert($rounds[$full]['report']['level'] === VacancyLevel::None
        && $rounds[$full]['is_system'] === true,
        'and once it goes stale the seat count has it back, rather than a morning ◎ holding all day');

    $assert(!in_array($DAY, array_column($service->sessionDaysNear($FAR, $companyA, 5), 'date'), true)
        || $idsOf($service->forSessions($DAY, $companyA, false, null, false)) !== [],
        'the days offered are days this screen can actually show rounds for');

    // --- narrowing the day down --------------------------------------------------
    // A whole festival is more cards than any screen holds, so the screens
    // that can be aimed at part of it should be.
    Db::execute('UPDATE companies SET is_published = 1 WHERE id = ?', [$companyA]);
    try {
        $all = $service->boardRows($DAY, 99);
        $assert($all !== [], 'the day has something on it to narrow');
        $assert($service->narrow($all, 'north') === [],
            'an area nothing is in comes back empty rather than coming back whole');
        $assert(count($service->narrow($all, null, ['見学'])) < count($all)
            && $service->narrow($all, null, ['見学']) !== [],
            'a word narrows to the programmes whose title carries it');
        $assert(count($service->narrow($all, null, ['見学', '体験']))
            > count($service->narrow($all, null, ['見学'])),
            'and two words ask for either, not both');
        $assert($service->narrow($all, null, ['ありえない語']) === [],
            'a word nothing matches shows nothing, which is visible rather than silent');
    } finally {
        Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$companyA]);
    }

    // The write side agrees with the read side, and what it agrees to has
    // changed: 予約必要 is on the board, so it can be reported on.
    $allowed = true;
    try {
        (new ReflectionMethod(AdminVacancyController::class, 'loadEvent'))
            ->invoke(new AdminVacancyController(), $booked, null);
    } catch (NotFoundException) {
        $allowed = false;
    }
    $assert($allowed, 'and the input screen takes a form posted against one');

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
            $assert($day['sessions'] === 3, 'each with how many sessions are on, so the right day is obvious');
        }
    }

    // --- the order the board shows things in -----------------------------------
    // ◎ ◯ △ ✕, then earliest first. Someone reading it from across a room is
    // asking "where can I go", so the places they can go come first.
    $assert(VacancyLevel::Open->rank() < VacancyLevel::Ample->rank()
        && VacancyLevel::Ample->rank() < VacancyLevel::Few->rank()
        && VacancyLevel::Few->rank() < VacancyLevel::None->rank(),
        'the marks rank ◎ ◯ △ ✕, which is the order a visitor wants them in');

    $level = static fn (string $v, bool $stale = false): array => [
        'level' => VacancyLevel::from($v), 'remaining' => null, 'is_stale' => $stale,
        'age_minutes' => 0, 'reported_at' => date('Y-m-d H:i:s'),
    ];
    $board = $service->sortForBoard([
        ['id' => 1, 'board_kind' => 'next',  'starts_at' => $DAY . ' 09:00:00', 'report' => $level('none')],
        ['id' => 2, 'board_kind' => 'later', 'starts_at' => $DAY . ' 15:00:00', 'report' => $level('open')],
        ['id' => 3, 'board_kind' => 'booth', 'report' => $level('ample')],
        ['id' => 4, 'board_kind' => 'next',  'starts_at' => $DAY . ' 10:00:00', 'report' => $level('few')],
        ['id' => 5, 'board_kind' => 'next',  'starts_at' => $DAY . ' 12:00:00', 'report' => null, 'fallback' => $level('open')],
        ['id' => 6, 'board_kind' => 'booth', 'report' => null, 'fallback' => null],
    ]);
    $assert($idsOf($board) === [5, 2, 3, 4, 1, 6],
        'the mark decides, then what kind of row it is, then the earlier round');
    $assert((int) $board[0]['id'] === 5,
        'a row showing the booth state in place of a missing round sorts on what it displays');
    $assert((int) $board[4]['id'] === 1,
        '✕ goes behind everything that is not');
    $assert((int) $board[5]['id'] === 6,
        'and an unreported row goes last of all - it is unknown, not full, which beats neither');

    // What somebody rang in goes in front of what the seat count worked out.
    // Without this a ◎ a company reported sits fifty-six cards deep, behind
    // every computed △ the booking system has - the one piece of news worth
    // crossing a hall for, and the hardest thing on the screen to find.
    $mixed = $service->sortForBoard([
        ['id' => 21, 'board_kind' => 'next', 'starts_at' => $DAY . ' 09:00:00',
         'report' => $level('few'), 'is_system' => true],
        ['id' => 22, 'board_kind' => 'booth', 'report' => $level('none'), 'is_system' => false],
    ]);
    $assert($idsOf($mixed) === [22, 21],
        'a person who reported ✕ still comes before a round the seat count called △');

    // Inside one group: ◎ ◯ △, and the earlier round first within a mark.
    $withinNext = $service->sortForBoard([
        ['id' => 11, 'board_kind' => 'next', 'starts_at' => $DAY . ' 16:00:00', 'report' => $level('open')],
        ['id' => 12, 'board_kind' => 'next', 'starts_at' => $DAY . ' 10:00:00', 'report' => $level('few')],
        ['id' => 13, 'board_kind' => 'next', 'starts_at' => $DAY . ' 11:00:00', 'report' => $level('open')],
    ]);
    $assert($idsOf($withinNext) === [13, 11, 12],
        'and inside a group the mark decides first, then the earlier round');

    // Ties keep catalogue order, which is what the printed programme uses.
    $sameMark = $service->sortForBoard([
        ['id' => 7, 'board_kind' => 'booth', 'report' => $level('open')],
        ['id' => 8, 'board_kind' => 'booth', 'report' => $level('open')],
    ]);
    $assert($idsOf($sameMark) === [7, 8],
        'two rows that cannot be told apart stay in the order they came in');

    // --- one list, rounds and programmes together -------------------------------
    // The board used to be two tabs, and a visitor had to know which one
    // answered their question. Nobody standing in front of a wall finds out:
    // there is nothing to press.
    // The board is the public view, so it only ever shows published companies.
    Db::execute('UPDATE companies SET is_published = 1 WHERE id = ?', [$companyA]);
    try {
        /** @return array<int, array<int, string>> event id => its rows' kinds */
        $kindsOf = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $out[(int) ($row['event_id'] ?? $row['id'])][] = (string) $row['board_kind'];
            }
            return $out;
        };

        $kinds = $kindsOf($service->boardRows($NEXT, 99));
        $assert(($kinds[$walkIn] ?? []) === ['booth'],
            'a programme with no rounds is one row on the same list');
        $assert(($kinds[$tour][0] ?? '') === 'next',
            'a programme with rounds is a row per round, the first marked as the next one');
        $assert($kinds[$tour] === ['next', 'later', 'later'],
            'every round of it is there, each knowing whether it is the next one or a later one');

        // Unbounded is unusable: 56 programmes at 7 rounds is 53 pages, and a
        // wall that comes round every 26 minutes answers nobody.
        $capped = $kindsOf($service->boardRows($NEXT, 1));
        $assert(max(array_map('count', $capped)) === 1,
            'rounds= caps how many of a programme appear, so the wall stays short enough to read');
        $assert(count($service->boardRows($NEXT, 99)) > count($service->boardRows($NEXT, 1)),
            'and raising it shows more of them again');
    } finally {
        Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$companyA]);
    }

    // --- ✕ on or off ------------------------------------------------------------
    $kept = $service->withoutFull([
        ['id' => 1, 'report' => $level('open')],
        ['id' => 2, 'report' => $level('none')],
        ['id' => 3, 'report' => null, 'fallback' => $level('none')],
        ['id' => 4, 'report' => null, 'fallback' => null],
    ]);
    $assert($idsOf($kept) === [1, 4],
        '✕ can be dropped when its card is worth more to a programme somebody can get into');
    $assert(in_array(4, $idsOf($kept), true),
        'but unreported stays - it is unknown, not full, and dropping it would be a guess');

    // --- the board can link out ---------------------------------------------------
    // Pressing a card goes to the programme's own 外部リンクURL. The column has
    // to reach the template, and nothing else here would notice if it stopped.
    Db::execute('UPDATE events SET external_url = ? WHERE id = ?', ['https://example.test/x', $standing]);
    $linked = null;
    foreach ($repo->eventsOn($DAY, $companyA, false) as $row) {
        if ((int) $row['id'] === $standing) {
            $linked = $row;
        }
    }
    $assert($linked !== null && (string) $linked['external_url'] === 'https://example.test/x',
        "the programme's external link is carried to the board");
    $assert(array_key_exists('external_url', $repo->sessionsOn($DAY, $companyA, $standing, false)[0] ?? []),
        'and to the per-round view, where the cards link out the same way');

    // --- rehearsing with the real programme list --------------------------------
    // sampleRows() answers "does the layout work". This answers "does it work
    // with OUR programmes", which is the question the real company names and
    // the real number of them decide - so it reads the database, and must
    // still write nothing to it.
    Db::execute('UPDATE companies SET is_published = 1 WHERE id = ?', [$companyA]);
    try {
        $before = (int) Db::scalar('SELECT COUNT(*) FROM vacancy_reports');
        $preview = $service->previewRows($DAY);
        $assert((int) Db::scalar('SELECT COUNT(*) FROM vacancy_reports') === $before,
            'a rehearsal over real programmes still writes nothing');

        // A row is either a round or a whole programme, so the programme it
        // belongs to is event_id on one and id on the other.
        $ofEvent = static fn (array $rows): array => array_map(
            static fn (array $r): int => (int) ($r['event_id'] ?? $r['id']),
            $rows
        );

        $previewIds = $ofEvent($preview);
        $assert(in_array($standing, $previewIds, true) && in_array($walkIn, $previewIds, true),
            "the day's real programmes are what is on screen, not invented ones");
        $assert(in_array($booked, $previewIds, true),
            'including the ones that take bookings - their titles are the long ones, '
            . 'and the point of the rehearsal is to see the real lengths');

        $flags = [];
        foreach ($preview as $row) {
            $flags[(int) ($row['event_id'] ?? $row['id'])] = (bool) $row['on_board'];
        }
        $assert($flags[$standing] === true && $flags[$booked] === false,
            'but each row says whether the real board will carry it, so the banner can count them');

        $assert(array_filter($preview, static fn (array $r): bool => $r['report'] === null) === [],
            'every row carries a mark - a rehearsal of blank cards shows nothing');

        // Derived from the id, not drawn at random: a wall that reshuffles on
        // every refresh tells you nothing about how the real one will read.
        $marks = static fn (array $rows): array => array_map(
            static fn (array $r): string => $r['report']['level']->value,
            $rows
        );
        $assert($marks($preview) === $marks($service->previewRows($DAY)),
            'and the same marks come back every time, so the screen does not flicker');

        // A far-off day is the day's line-up too, and the day's line-up there
        // is the walk-up booths and nothing else - they have no date of their
        // own. What must NOT follow them across is a programme whose rounds
        // are elsewhere, or the rehearsal would be the catalogue, not a day.
        $far = $ofEvent($service->previewRows('2035-01-01'));
        $assert(in_array($walkIn, $far, true),
            'a walk-up booth rehearses on any day, because it runs on any day');
        $assert(!in_array($booked, $far, true),
            'a programme whose rounds are on another day does not, or this would be the catalogue');
    } finally {
        Db::execute('UPDATE companies SET is_published = 0 WHERE id = ?', [$companyA]);
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
