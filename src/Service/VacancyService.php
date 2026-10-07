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
