<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\VacancyLevel;
use App\Repository\VacancyRepository;

/**
 * Assembles the day's availability for whoever is reading it.
 *
 * Two shapes, because a visitor asks two different questions and they want
 * different data:
 *
 *   - "where can I go now?"      -> one row per booth, from the reports that
 *                                   carry no session (forEvents)
 *   - "is the 15:00 slot open?"  -> one row per session (forSessions)
 *
 * A session with no report of its own falls back to the booth's current state,
 * flagged so the reader can see it is a different claim about a different
 * thing. Something known about the booth beats nothing known about the slot.
 */
final class VacancyService
{
    /**
     * After this long without an update, a report stops being evidence.
     *
     * The dangerous failure on the day is a stale ◎ sending people to a booth
     * that filled up an hour ago, so the age is shown rather than hidden, and
     * the row is greyed rather than dropped - "we last heard at 09:10" is
     * still worth more than silence.
     */
    public const STALE_MINUTES = 90;

    public function __construct(
        private readonly VacancyRepository $reports = new VacancyRepository(),
    ) {
    }

    /**
     * One entry per booth running that day, in catalogue order.
     *
     * Each row carries is_walk_in: true for a booth that takes a current
     * status and nothing else, which is a booth with no sessions registered
     * that day. 予約不要 does NOT decide this - a programme that hands its
     * tickets out on the door can still run in rounds, and whether it does
     * is recorded in event_sessions. What 予約不要 does decide is that the
     * booth is listed at all (eventsOn), on any day it might be asked about.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forEvents(string $date, ?int $companyId = null, bool $publishedOnly = true): array
    {
        $events = $this->reports->eventsOn($date, $companyId, $publishedOnly);
        $current = $this->reports->currentByEvent($date, $companyId);
        $wayIn = $this->wayInByEvent($date, $companyId, $publishedOnly);

        $out = [];
        foreach ($events as $event) {
            $id = (int) $event['id'];
            $report = $this->decorate($current[$id] ?? null);

            $event['report'] = $report;
            $event['is_walk_in'] = (int) $event['session_count'] === 0;

            /*
             * "Can I get in there now" is the question this view answers, and
             * for a programme that takes bookings the seat counts can answer
             * it - not round by round, but across the rounds still to come.
             * Without this the one tab a visitor is most likely to open said
             * 未報告 against every bookable programme on the site.
             */
            $event['seats_left'] = isset($wayIn[$id]) && $wayIn[$id] ? 1 : 0;
            $event['waitlist_count'] = 0;
            $event = $this->withSystemAnswer(
                $event + ['booking_required' => $event['booking_required']],
                $report
            );

            // No rounds left today: the seat counts have nothing to say, so
            // the row goes back to whatever a person said, or to nothing.
            if (!array_key_exists($id, $wayIn)) {
                $event['report'] = $report;
                $event['is_system'] = false;
            }

            $out[] = $event;
        }
        return $out;
    }

    /**
     * Days with sessions, nearest $date first, for the "nothing on today"
     * notice on the input screen.
     *
     * @return array<int, array{date: string, sessions: int}>
     */
    public function sessionDaysNear(string $date, ?int $companyId = null, int $limit = 3): array
    {
        return $this->reports->sessionDaysNear($date, $companyId, $limit);
    }

    /**
     * One entry per session running that day, in time order.
     *
     * @param bool $upcomingOnly Drop sessions that have already finished. The
     *        public page wants this; the office checking its work does not.
     * @return array<int, array<string, mixed>>
     */
    public function forSessions(
        string $date,
        ?int $companyId = null,
        bool $upcomingOnly = true,
        ?int $eventId = null,
        bool $publishedOnly = true,
    ): array {
        $sessions = $this->reports->sessionsOn($date, $companyId, $eventId, $publishedOnly);
        $perSession = $this->reports->currentBySession($date, $companyId);
        $perEvent = $this->reports->currentByEvent($date, $companyId);

        $now = date('Y-m-d H:i:s');

        $out = [];
        foreach ($sessions as $session) {
            if ($upcomingOnly && (string) $session['ends_at'] < $now) {
                continue;
            }

            $report = $this->decorate($perSession[(int) $session['id']] ?? null);

            // Nothing about this slot: say so, and offer what is known about
            // the booth under a different label rather than dressing it up as
            // a report about the slot.
            $fallback = $report === null
                ? $this->decorate($perEvent[(int) $session['event_id']] ?? null)
                : null;

            $session['report'] = $report;
            $session['fallback'] = $fallback;
            $session = $this->withSystemAnswer($session, $report ?? $fallback);

            $session['in_progress'] = (string) $session['starts_at'] <= $now
                && $now < (string) $session['ends_at'];

            $out[] = $session;
        }
        return $out;
    }

    /**
     * Per programme: is there any way into a round still to come today?
     *
     * The "now" view is one row per programme, and a programme's rounds each
     * have their own seat count, so the answer across them is "any of them".
     * One query, grouped here rather than in SQL because the rule that turns
     * seats into a way in lives in VacancyLevel and should stay there.
     *
     * Absent from the result means no rounds left today - which is not the
     * same as no way in, and must not be reported as ✕.
     *
     * @return array<int, bool> event id => somewhere to get in
     */
    private function wayInByEvent(string $date, ?int $companyId, bool $publishedOnly): array
    {
        $now = date('Y-m-d H:i:s');

        $out = [];
        foreach ($this->reports->sessionsOn($date, $companyId, null, $publishedOnly) as $session) {
            if ((int) $session['booking_required'] !== 1) {
                continue;
            }
            if ((string) $session['ends_at'] < $now) {
                continue;
            }

            $id = (int) $session['event_id'];
            $open = VacancyLevel::fromSeats(
                (int) $session['seats_left'],
                (int) $session['waitlist_count']
            ) !== VacancyLevel::None;

            $out[$id] = ($out[$id] ?? false) || $open;
        }
        return $out;
    }

    /**
     * Let the booking system answer for a round nobody is speaking for.
     *
     * A person beats the seat count, which is the whole arrangement: the
     * database knows how many seats were sold and nothing about the queue in
     * the corridor, so whoever is standing there outranks it.
     *
     * But only while they are still speaking. A report goes stale after
     * ninety minutes, and a stale report is not a person's word any more -
     * it is a trace of one. The seat count is current by construction, so
     * past that line it takes over. Otherwise a mark typed at nine in the
     * morning would hold the booth's card all day with the accurate number
     * sitting underneath it.
     *
     * is_system is carried so the ordering can put the rows somebody actually
     * reported on in front of the rows nobody has - see sortForBoard().
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed>|null $human the report on display, if any
     * @return array<string, mixed>
     */
    private function withSystemAnswer(array $session, ?array $human): array
    {
        $session['is_system'] = false;

        if ((int) ($session['booking_required'] ?? 0) !== 1) {
            return $session;  // nothing to count: no seats are sold for it
        }
        if ($human !== null && $human['is_stale'] === false) {
            return $session;
        }

        $level = VacancyLevel::fromSeats(
            (int) ($session['seats_left'] ?? 0),
            (int) ($session['waitlist_count'] ?? 0)
        );

        /*
         * Shown exactly like a report, on purpose. It is as true as one and
         * truer than a stale one, and a card that explained where its mark
         * came from would spend a wall's worth of room on a distinction the
         * reader cannot act on.
         */
        $session['report'] = [
            'level' => $level,
            'remaining' => null,
            'note' => null,
            'reported_at' => date('Y-m-d H:i:s'),
            'reported_by' => 'system',
            'age_minutes' => 0,
            'is_stale' => false,
        ];
        $session['fallback'] = null;
        $session['is_system'] = true;

        return $session;
    }

    /**
     * Turn a stored row into something a template can render without thinking:
     * the level as an object, how old it is, and whether that age matters.
     *
     * @param array<string, mixed>|null $report
     * @return array<string, mixed>|null
     */
    private function decorate(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        $level = VacancyLevel::tryFrom((string) $report['level']);
        if ($level === null) {
            // A value this build does not know. Treated as no report at all
            // rather than guessed at: showing the wrong mark is worse than
            // showing none, and this is the only place it could happen.
            return null;
        }

        $reportedAt = strtotime((string) $report['reported_at']);
        $ageMinutes = (int) max(0, floor((time() - $reportedAt) / 60));

        $report['level'] = $level;
        $report['age_minutes'] = $ageMinutes;
        $report['is_stale'] = $ageMinutes >= self::STALE_MINUTES;
        $report['remaining'] = $report['remaining'] !== null ? (int) $report['remaining'] : null;

        return $report;
    }

    /**
     * One list for the board: a row per upcoming round, or one row for a
     * programme that has no rounds that day.
     *
     * The board used to be two screens - programmes on one tab, rounds on
     * another - and a visitor had to know which tab answered their question.
     * One list answers both, as long as it is ordered by what they can act
     * on; see sortForBoard().
     *
     * $rounds caps how many of a programme's upcoming rounds appear. Without
     * a cap this is unbounded: fifty-six programmes at seven rounds each is
     * four hundred cards, which at eight to a page is a wall that takes
     * twenty-six minutes to come round again - and a visitor looking at it
     * for thirty seconds has a one-in-fifty chance of seeing their booth.
     *
     * @return array<int, array<string, mixed>>
     */
    public function boardRows(string $date, int $rounds = 3): array
    {
        return $this->assemble($this->forEvents($date), $this->forSessions($date), $rounds);
    }

    /**
     * Fold programmes and their rounds into the single list the board shows.
     *
     * Each row is tagged with what it is, because the order depends on it:
     * 'next' for a programme's first upcoming round, 'later' for the ones
     * behind it, 'booth' for a programme with no rounds that day.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $sessions in time order
     * @return array<int, array<string, mixed>>
     */
    private function assemble(array $events, array $sessions, int $rounds): array
    {
        $byEvent = [];
        foreach ($sessions as $session) {
            $byEvent[(int) $session['event_id']][] = $session;
        }

        $out = [];
        foreach ($events as $event) {
            $mine = $byEvent[(int) $event['id']] ?? [];

            if ($mine === []) {
                $event['board_kind'] = 'booth';
                $out[] = $event;
                continue;
            }

            foreach (array_slice($mine, 0, max(1, $rounds)) as $i => $session) {
                $session['board_kind'] = $i === 0 ? 'next' : 'later';
                $out[] = $session;
            }
        }
        return $out;
    }

    /**
     * The order the board shows things in.
     *
     * Rows somebody reported on come first, then the ones only the booking
     * system can speak for. That is not politeness: the day the booking
     * system has every round of every programme in it, the reported rows are
     * fifty-six cards deep otherwise, and a ◎ that a company rang in sits on
     * page eight behind a wall of computed △. The one piece of news worth
     * crossing a hall for would be the hardest thing on the screen to find.
     *
     * Then ◎ ◯ △ ✕, and only then what kind of row it is:
     *
     *   1. each programme's NEXT round        - what a visitor can act on now
     *   2. programmes with no rounds          - always open, always actionable
     *   3. the rounds after the next one      - worth knowing, not urgent
     *
     * and earliest first within that.
     *
     * Only the board. The public page keeps its headings - one per company on
     * the "now" tab, one per time on the "rounds" tab - and sorting by mark
     * would scatter the rows out from under them. The board has no headings
     * to break, and somebody reading it from across a room is asking "where
     * can I go", not "what is this company running".
     *
     * A row showing the booth's state in place of a missing round sorts on
     * what it displays, because that is what the reader sees.
     *
     * Ties keep the order they arrived in - catalogue order - because usort
     * has been stable since PHP 8.0, and that is the order the office and
     * the printed programme both use.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function sortForBoard(array $rows): array
    {
        $key = static function (array $row): array {
            $report = $row['report'] ?? $row['fallback'] ?? null;

            $kind = match ($row['board_kind'] ?? 'booth') {
                'next'  => 1,
                'booth' => 2,
                default => 3,
            };

            $when = (string) ($row['starts_at'] ?? $row['first_starts_at'] ?? '');

            return [
                // Unreported last of all: the absence of an answer cannot
                // come before ✕, which is one.
                $report === null ? 2 : (($row['is_system'] ?? false) ? 1 : 0),
                $report === null ? 0 : $report['level']->rank(),
                $kind,
                // A programme with no rounds has no time to sort on; it goes
                // after the timed ones rather than in front of all of them.
                $when === '' ? '9999-12-31 23:59:59' : $when,
            ];
        };

        usort($rows, static fn (array $a, array $b): int => $key($a) <=> $key($b));
        return $rows;
    }

    /**
     * Narrow the day down to part of it.
     *
     * Two dials, because the board got long the moment it started carrying
     * every programme: a whole festival is fifty-odd cards before anything
     * repeats, and nobody reads a wall that takes ten minutes to come round.
     *
     * $area  matches companies.area, so a screen hung in one hall shows that
     *        hall. It is the dial that pays: four areas, four quarters.
     * $words is matched against the programme's title, any one of them. A
     *        proper 種別 column would be better and is not here, so this is
     *        the nearest honest thing - the titles end in what they are
     *        (…見学ツアー, …ワークショップ), so a word does the work a column
     *        would have. Where a title says nothing useful, nothing matches,
     *        and that is visible rather than silent.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $words
     * @return array<int, array<string, mixed>>
     */
    public function narrow(array $rows, ?string $area = null, array $words = []): array
    {
        $words = array_values(array_filter(array_map('trim', $words), static fn (string $w): bool => $w !== ''));

        return array_values(array_filter($rows, static function (array $row) use ($area, $words): bool {
            if ($area !== null && (string) ($row['area'] ?? '') !== $area) {
                return false;
            }
            if ($words === []) {
                return true;
            }

            $title = (string) ($row['event_title'] ?? $row['title'] ?? '');
            foreach ($words as $word) {
                if (mb_stripos($title, $word) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Drop the ✕ rows.
     *
     * ✕ costs a card's worth of room to say "do not come here". Worth it by
     * default - "full" is an answer, and without it a reader cannot tell a
     * full programme from one nobody has reported on - but on a wall with
     * more programmes than fit, that room is better spent on the ones
     * somebody can still get into.
     *
     * Unreported rows are left alone: they are not full, they are unknown.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function withoutFull(array $rows): array
    {
        return array_values(array_filter($rows, static function (array $row): bool {
            $report = $row['report'] ?? $row['fallback'] ?? null;
            return $report === null || $report['level'] !== VacancyLevel::None;
        }));
    }

    /**
     * The real programme list for a day, with invented marks on it.
     *
     * For looking at the board before the day it is for. sampleRows() answers
     * "does the layout work"; this answers "does it work with OUR programmes"
     * - the real company names, the real titles, the real number of them,
     *   which is what actually decides whether a wall is readable.
     *
     * Nothing is written. The marks are derived from the row's id rather than
     * drawn at random, so the screen does not reshuffle itself every time it
     * refreshes - a rehearsal that flickers tells you nothing about a wall.
     *
     * Programmes that take bookings are included even though the real board
     * will not carry them: the point here is the shape of the day's line-up,
     * and leaving them out would hide how long the real titles are. Each row
     * says which it is in on_board, and the banner says how many of them the
     * real thing will show.
     *
     * @return array<int, array<string, mixed>>
     */
    public function previewRows(string $date, int $rounds = 3): array
    {
        $now = date('Y-m-d H:i:s');
        $sessions = array_values(array_filter(
            $this->reports->sessionsOn($date),
            // Finished rounds are off the real board, so they are off the
            // rehearsal too - otherwise a morning rehearsal of this afternoon
            // shows cards the afternoon will not.
            static fn (array $s): bool => (string) $s['ends_at'] >= $now
        ));

        return $this->assemble(
            $this->invent($this->reports->eventsOn($date)),
            $this->invent($sessions),
            $rounds
        );
    }

    /**
     * Hang an invented mark off each row, without writing any of it down.
     *
     * Derived from the row's id rather than drawn at random, so a rehearsal
     * left running does not reshuffle itself every time it refreshes - a
     * wall that flickers tells you nothing about how the real one will read.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function invent(array $rows): array
    {
        $marks = VacancyLevel::cases();
        $count = count($rows);

        $out = [];
        foreach ($rows as $i => $row) {
            $id = (int) $row['id'];
            $level = $marks[$id % count($marks)];

            // One row old enough to grey out. That state cannot be staged on
            // the day, and it is the one worth seeing beforehand.
            $stale = $count > 2 ? $i === 2 : $i === $count - 1;
            $age = $stale ? self::STALE_MINUTES + 25 : ($id % 7) * 9 + 1;

            $remaining = match (true) {
                $level === VacancyLevel::None => 0,
                $id % 3 === 0 => 2 + $id % 15,
                default => null,
            };

            $row['report'] = $this->decorate([
                'level' => $level->value,
                'remaining' => $remaining,
                'note' => null,
                'reported_at' => date('Y-m-d H:i:s', time() - $age * 60),
                'reported_by' => 'preview',
            ]);
            $row['fallback'] = null;
            $row['on_board'] = (int) ($row['booking_required'] ?? 1) === 0;

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Rows that look like a busy day, for checking the wall display before
     * there is a day to check it on.
     *
     * Built in memory and written nowhere. Every state the screen can show
     * is present - all four marks, a ticket count, and one report old
     * enough to grey out - because the point of a rehearsal is to see the
     * cases you cannot conjure on the day.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sampleRows(int $count = 8): array
    {
        // Both shapes of row, because the board carries both: a programme
        // that runs in rounds, and one that is simply open. A rehearsal that
        // only showed one of them would not be a rehearsal.
        $recipe = [
            ['サンプル工業株式会社', 'east', '工場見学ツアー', VacancyLevel::Open, null, 3, 'next', '11:00'],
            ['サンプル工業株式会社', 'east', '組立体験', VacancyLevel::Few, 6, 12, 'booth', null],
            ['みほん電機株式会社', 'south', '製品体験ワークショップ', VacancyLevel::None, 0, 25, 'next', '11:30'],
            ['みほん電機株式会社', 'south', '技術説明会', VacancyLevel::Ample, null, 8, 'booth', null],
            ['れい精密工業株式会社', 'north', 'ロボット操作体験', VacancyLevel::Open, 20, 2, 'next', '12:00'],
            ['れい精密工業株式会社', 'north', '切削加工の実演', VacancyLevel::Few, 2, 40, 'later', '15:00'],
            ['テスト食品株式会社', 'main', '試食と工場案内', VacancyLevel::Ample, null, 15, 'booth', null],
            ['テスト食品株式会社', 'main', 'パン作り体験', VacancyLevel::None, 0, self::STALE_MINUTES + 30, 'later', '16:30'],
        ];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            [$company, $area, $title, $level, $remaining, $age, $kind, $at] = $recipe[$i % count($recipe)];
            $out[] = [
                'id' => -($i + 1),
                'title' => $title,
                'event_title' => $title,
                'company_name' => $company,
                'area' => $area,
                'board_kind' => $kind,
                'starts_at' => $at !== null ? date('Y-m-d ') . $at . ':00' : null,
                'ends_at' => null,
                'in_progress' => false,
                'fallback' => null,
                'report' => $this->decorate([
                    'level' => $level->value,
                    'remaining' => $remaining,
                    'note' => null,
                    'reported_at' => date('Y-m-d H:i:s', time() - $age * 60),
                    'reported_by' => 'sample',
                ]),
            ];
        }
        return $out;
    }

    /** Today, in the timezone the application runs in. */
    public static function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * When to say a report made now, on the screen for $date, happened.
     *
     * A report carrying no session has no date but the one it was typed on,
     * so that is the day it belongs to - and the office works a day ahead.
     * Stamping the wall clock meant a mark entered on the screen for next
     * Friday was saved against today and then could not be read back: the
     * operator was told it worked and nothing changed.
     *
     * So the date follows the screen and only the time of day is the clock.
     * On the day itself the two are the same thing.
     */
    public static function stampFor(string $date): string
    {
        return $date . ' ' . date('H:i:s');
    }

    /** A date from a query string, or today. */
    public static function dateFrom(string $raw): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1 ? trim($raw) : self::today();
    }
}
