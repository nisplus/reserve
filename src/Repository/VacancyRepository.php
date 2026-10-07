<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

/**
 * Day-of availability reports.
 *
 * Append-only: a report is never updated, only followed by a later one. The
 * current value is the newest row for a key, which is why every read here goes
 * through the same GROUP BY / MAX(id) / join-back shape.
 *
 * Nothing in this class writes to any table but vacancy_reports. The event,
 * session and company rows it joins are read only - that is the whole basis
 * of the claim that this feature cannot disturb the booking system.
 */
final class VacancyRepository
{
    public function add(
        int $eventId,
        ?int $sessionId,
        string $level,
        ?int $remaining,
        ?string $note,
        string $reportedBy,
        ?string $reportedAt = null,
    ): int {
        Db::execute(
            'INSERT INTO vacancy_reports
               (event_id, session_id, level, remaining, note, reported_at, reported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $eventId,
                $sessionId,
                $level,
                $remaining,
                $note,
                $reportedAt ?? date('Y-m-d H:i:s'),
                $reportedBy,
            ]
        );
        return Db::lastInsertId();
    }

    /**
     * The newest report per event, for events running on $date.
     *
     * "Per event" here means the report that carries no session - the booth's
     * current state, which is what the default public view shows.
     *
     * A report with no session belongs to the day it was made: there is no
     * session to take a date from, and this is already how reset_vacancy.php
     * decides what a day's reports are. Without the filter, yesterday's ◎
     * would still be showing as today's, which is the one failure this whole
     * feature exists to prevent.
     *
     * @return array<int, array<string, mixed>> keyed by event id
     */
    public function currentByEvent(string $date, ?int $companyId = null): array
    {
        [$scope, $params] = $this->scope($date, $companyId);
        array_unshift($params, $date);

        $rows = Db::select(
            "SELECT v.event_id, v.level, v.remaining, v.note, v.reported_at, v.reported_by
               FROM (
                     SELECT r.event_id, MAX(r.id) AS latest
                       FROM vacancy_reports r
                       JOIN events e ON e.id = r.event_id
                      WHERE r.session_id IS NULL
                        AND DATE(r.reported_at) = ?
                        {$scope}
                      GROUP BY r.event_id
                    ) pick
               JOIN vacancy_reports v ON v.id = pick.latest",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['event_id']] = $row;
        }
        return $out;
    }

    /**
     * The newest report per session, for sessions starting on $date.
     *
     * @return array<int, array<string, mixed>> keyed by session id
     */
    public function currentBySession(string $date, ?int $companyId = null): array
    {
        $params = [$date];
        $scope = '';
        if ($companyId !== null) {
            $scope = 'AND e.company_id = ?';
            $params[] = $companyId;
        }

        $rows = Db::select(
            "SELECT v.session_id, v.level, v.remaining, v.note, v.reported_at, v.reported_by
               FROM (
                     SELECT r.session_id, MAX(r.id) AS latest
                       FROM vacancy_reports r
                       JOIN event_sessions s ON s.id = r.session_id
                       JOIN events e         ON e.id = s.event_id
                      WHERE DATE(s.starts_at) = ?
                        {$scope}
                      GROUP BY r.session_id
                    ) pick
               JOIN vacancy_reports v ON v.id = pick.latest",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['session_id']] = $row;
        }
        return $out;
    }

    /**
     * Booths to show for $date, in the order the public catalogue uses: area,
     * then company, then the event's own sort order.
     *
     * Three kinds of event belong here:
     *
     *   - 予約不要 (booking_required = 0), WHATEVER sessions it has. A booth
     *     that takes no bookings is a walk-up booth all day, so its sessions
     *     describe when staff are there, not what can be reserved. There is
     *     no per-slot ticket count to report, only "how is it right now" -
     *     and sessionsOn() leaves these out for the same reason.
     *   - those with a session that day, listed per session as well;
     *   - those with NO sessions at all, which also take a single current
     *     status and nothing else.
     *
     * An event that takes bookings and whose sessions are all on OTHER days
     * is deliberately not here: it is not running today, so there is nothing
     * to report about it. That is also why a day outside the festival shows
     * only the walk-up booths - see sessionDaysNear(), which the input screen
     * uses to point at the days that do have sessions.
     *
     * session_count is how many sessions this booth has on $date; the input
     * screen reads it, together with booking_required, to decide whether a
     * per-session screen exists for a row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventsOn(string $date, ?int $companyId = null, bool $publishedOnly = true): array
    {
        $params = [$date];
        $where = '';
        if ($companyId !== null) {
            $where .= ' AND e.company_id = ?';
            $params[] = $companyId;
        }
        if ($publishedOnly) {
            $where .= ' AND e.is_published = 1 AND c.is_published = 1';
        }

        return Db::select(
            "SELECT e.id, e.title, e.venue, e.booking_required,
                    c.id AS company_id, c.name AS company_name, c.area,
                    MIN(today.starts_at) AS first_starts_at,
                    MAX(today.ends_at)   AS last_ends_at,
                    COUNT(today.id)      AS session_count
               FROM events e
               JOIN companies c ON c.id = e.company_id
               LEFT JOIN event_sessions today
                      ON today.event_id = e.id AND DATE(today.starts_at) = ?
              WHERE (
                      e.booking_required = 0
                      OR today.id IS NOT NULL
                      OR NOT EXISTS (SELECT 1 FROM event_sessions any_s
                                      WHERE any_s.event_id = e.id)
                    )
                    {$where}
              GROUP BY e.id, e.title, e.venue, e.booking_required,
                       c.id, c.name, c.area, e.sort_order, c.sort_order
              ORDER BY c.sort_order, c.id, e.sort_order, e.id",
            $params
        );
    }

    /**
     * Sessions running on $date, in time order.
     *
     * 予約不要 booths are left out even when they have sessions: nothing can
     * be reserved for a slot there, so a per-slot ticket count would be a
     * number about nothing. They are walk-up booths, reported once through
     * eventsOn() as a current status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsOn(string $date, ?int $companyId = null, ?int $eventId = null, bool $publishedOnly = true): array
    {
        $params = [$date];
        $where = ' AND e.booking_required = 1';
        if ($companyId !== null) {
            $where .= ' AND e.company_id = ?';
            $params[] = $companyId;
        }
        if ($eventId !== null) {
            $where .= ' AND e.id = ?';
            $params[] = $eventId;
        }
        if ($publishedOnly) {
            $where .= ' AND e.is_published = 1 AND c.is_published = 1';
        }

        return Db::select(
            "SELECT s.id, s.starts_at, s.ends_at, s.status,
                    e.id AS event_id, e.title AS event_title, e.venue,
                    c.id AS company_id, c.name AS company_name, c.area
               FROM event_sessions s
               JOIN events e    ON e.id = s.event_id
               JOIN companies c ON c.id = e.company_id
              WHERE DATE(s.starts_at) = ? {$where}
              ORDER BY s.starts_at, c.sort_order, c.id, e.sort_order, e.id",
            $params
        );
    }

    /**
     * Days that have sessions, the ones nearest $date first.
     *
     * The input screen opens on today, and on every day but the festival's
     * own that leaves nothing but the walk-up booths on screen. Reported as
     * "only the booths with no sessions are showing" - which was two faults
     * at once, and this is the half that is not a bug: the office needs to be
     * told which day to go to, not left to guess with the arrows.
     *
     * @return array<int, array{date: string, sessions: int}>
     */
    public function sessionDaysNear(string $date, ?int $companyId = null, int $limit = 3): array
    {
        $params = [];
        $where = 'WHERE e.booking_required = 1';
        if ($companyId !== null) {
            $where .= ' AND e.company_id = ?';
            $params[] = $companyId;
        }
        $params[] = $date;

        $statement = Db::pdo()->prepare(
            "SELECT DATE(s.starts_at) AS d, COUNT(*) AS n
               FROM event_sessions s
               JOIN events e ON e.id = s.event_id
               {$where}
              GROUP BY d
              ORDER BY ABS(DATEDIFF(d, ?)), d
              LIMIT ?"
        );
        $position = 1;
        foreach ($params as $param) {
            $statement->bindValue($position++, $param);
        }
        $statement->bindValue($position, $limit, \PDO::PARAM_INT);
        $statement->execute();

        $rows = array_map(
            static fn (array $row): array => [
                'date' => (string) $row['d'],
                'sessions' => (int) $row['n'],
            ],
            $statement->fetchAll()
        );

        // Nearest-first picked them; chronological reads better in a list.
        usort($rows, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        return $rows;
    }

    /**
     * The last few reports, newest first - shown under the input screen so the
     * operator can see what they and everyone else just entered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit, ?int $companyId = null): array
    {
        $where = '';
        $params = [];
        if ($companyId !== null) {
            $where = 'WHERE e.company_id = ?';
            $params[] = $companyId;
        }

        $statement = Db::pdo()->prepare(
            "SELECT v.id, v.level, v.remaining, v.reported_at, v.reported_by,
                    e.title AS event_title, c.name AS company_name,
                    s.starts_at, s.ends_at
               FROM vacancy_reports v
               JOIN events e    ON e.id = v.event_id
               JOIN companies c ON c.id = e.company_id
               LEFT JOIN event_sessions s ON s.id = v.session_id
               {$where}
              ORDER BY v.id DESC
              LIMIT ?"
        );
        $position = 1;
        foreach ($params as $param) {
            $statement->bindValue($position++, $param);
        }
        $statement->bindValue($position, $limit, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function scope(string $date, ?int $companyId): array
    {
        $params = [];
        /*
         * The same three kinds as eventsOn(), and for the same reason it must
         * stay in step with it: an event the input screen lists but this
         * scope drops can be reported on, and the report never comes back
         * out - a worse failure than refusing the report would have been.
         */
        $scope = 'AND (e.booking_required = 0
                      OR EXISTS (SELECT 1 FROM event_sessions s
                                  WHERE s.event_id = e.id AND DATE(s.starts_at) = ?)
                      OR NOT EXISTS (SELECT 1 FROM event_sessions any_s
                                      WHERE any_s.event_id = e.id))';
        $params[] = $date;

        if ($companyId !== null) {
            $scope .= ' AND e.company_id = ?';
            $params[] = $companyId;
        }
        return [$scope, $params];
    }
}
