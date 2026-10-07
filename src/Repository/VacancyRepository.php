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
     * @return array<int, array<string, mixed>> keyed by event id
     */
    public function currentByEvent(string $date, ?int $companyId = null): array
    {
        [$scope, $params] = $this->scope($date, $companyId);

        $rows = Db::select(
            "SELECT v.event_id, v.level, v.remaining, v.note, v.reported_at, v.reported_by
               FROM (
                     SELECT r.event_id, MAX(r.id) AS latest
                       FROM vacancy_reports r
                       JOIN events e ON e.id = r.event_id
                      WHERE r.session_id IS NULL
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
     * Published events running on $date, in the order the public catalogue
     * uses: area, then company, then the event's own sort order.
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
                    MIN(s.starts_at) AS first_starts_at,
                    MAX(s.ends_at)   AS last_ends_at,
                    COUNT(s.id)      AS session_count
               FROM events e
               JOIN companies c      ON c.id = e.company_id
               JOIN event_sessions s ON s.event_id = e.id AND DATE(s.starts_at) = ?
              WHERE 1 = 1 {$where}
              GROUP BY e.id, e.title, e.venue, e.booking_required,
                       c.id, c.name, c.area, e.sort_order, c.sort_order
              ORDER BY c.sort_order, c.id, e.sort_order, e.id",
            $params
        );
    }

    /**
     * Sessions running on $date, in time order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessionsOn(string $date, ?int $companyId = null, ?int $eventId = null, bool $publishedOnly = true): array
    {
        $params = [$date];
        $where = '';
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
        $scope = 'AND EXISTS (SELECT 1 FROM event_sessions s
                               WHERE s.event_id = e.id AND DATE(s.starts_at) = ?)';
        $params[] = $date;

        if ($companyId !== null) {
            $scope .= ' AND e.company_id = ?';
            $params[] = $companyId;
        }
        return [$scope, $params];
    }
}
