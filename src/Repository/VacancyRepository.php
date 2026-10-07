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
        // The same events as eventsOn(), and it must stay in step with it: an
        // event the input screen lists but this query drops can be reported
        // on, and the report never comes back out - a worse failure than
        // refusing the report would have been.
        $scope = 'AND e.booking_required = 0';
        $params = [$date];
        if ($companyId !== null) {
            $scope .= ' AND e.company_id = ?';
            $params[] = $companyId;
        }

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
     * ONLY 予約不要 (booking_required = 0). A programme that takes bookings
     * already has a seat count the booking system can answer with, and a
     * second, hand-typed number beside it would only disagree with it. This
     * board is for the walk-up programmes, where nothing but a person at the
     * booth knows how full it is.
     *
     * Every 予約不要 booth is listed on every day. It has no session to take
     * a date from, so there is no day it is not worth asking about.
     *
     * session_count is how many sessions the booth has on $date, straight
     * from event_sessions. Taking no bookings does not mean having no rounds:
     * a workshop can run 10:00 / 13:00 / 15:00 and hand its tickets out on
     * the door. Where there are rounds, the input screen offers the
     * per-session form; where there are none, the marks and nothing else.
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
              WHERE e.booking_required = 0
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
     * 予約不要 only, to match eventsOn(). Taking no bookings does not mean
     * having no rounds: a workshop that runs 10:00, 13:00 and 15:00 and hands
     * its tickets out on the door has three separate things to say about, and
     * event_sessions is where that is recorded - so that is where the rounds
     * come from, rather than being inferred from anything else.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsOn(string $date, ?int $companyId = null, ?int $eventId = null, bool $publishedOnly = true): array
    {
        $params = [$date];
        $where = ' AND e.booking_required = 0';
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
     * 予約不要 only, so the days offered are days this screen can actually
     * show something for. The input screen opens on today, and on a day with
     * no rounds on it there is nothing but the marks; the office needs to be
     * told which day to go to rather than left to guess with the arrows.
     *
     * @return array<int, array{date: string, sessions: int}>
     */
    public function sessionDaysNear(string $date, ?int $companyId = null, int $limit = 3): array
    {
        $params = [];
        $where = 'WHERE e.booking_required = 0';
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

}
