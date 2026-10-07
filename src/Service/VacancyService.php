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
     * @return array<int, array<string, mixed>>
     */
    public function forEvents(string $date, ?int $companyId = null, bool $publishedOnly = true): array
    {
        $events = $this->reports->eventsOn($date, $companyId, $publishedOnly);
        $current = $this->reports->currentByEvent($date, $companyId);

        $out = [];
        foreach ($events as $event) {
            $report = $current[(int) $event['id']] ?? null;
            $event['report'] = $this->decorate($report);
            $out[] = $event;
        }
        return $out;
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

            $report = $perSession[(int) $session['id']] ?? null;
            $session['report'] = $this->decorate($report);

            // Nothing about this slot: say so, and offer what is known about
            // the booth under a different label rather than dressing it up as
            // a report about the slot.
            $session['fallback'] = $report === null
                ? $this->decorate($perEvent[(int) $session['event_id']] ?? null)
                : null;

            $session['in_progress'] = (string) $session['starts_at'] <= $now
                && $now < (string) $session['ends_at'];

            $out[] = $session;
        }
        return $out;
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
        $recipe = [
            ['サンプル工業株式会社', '工場見学ツアー', VacancyLevel::Open, null, 3],
            ['サンプル工業株式会社', '組立体験', VacancyLevel::Few, 6, 12],
            ['みほん電機株式会社', '製品体験ワークショップ', VacancyLevel::None, 0, 25],
            ['みほん電機株式会社', '技術説明会', VacancyLevel::Ample, null, 8],
            ['れい精密工業株式会社', 'ロボット操作体験', VacancyLevel::Open, 20, 2],
            ['れい精密工業株式会社', '切削加工の実演', VacancyLevel::Few, 2, 40],
            ['テスト食品株式会社', '試食と工場案内', VacancyLevel::Ample, null, 15],
            ['テスト食品株式会社', 'パン作り体験', VacancyLevel::None, 0, self::STALE_MINUTES + 30],
        ];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            [$company, $title, $level, $remaining, $age] = $recipe[$i % count($recipe)];
            $out[] = [
                'id' => -($i + 1),
                'title' => $title,
                'event_title' => $title,
                'company_name' => $company,
                'starts_at' => date('Y-m-d 10:00:00'),
                'ends_at' => date('Y-m-d 11:00:00'),
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

    /** A date from a query string, or today. */
    public static function dateFrom(string $raw): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($raw)) === 1 ? trim($raw) : self::today();
    }
}
