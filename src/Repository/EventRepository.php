<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

final class EventRepository
{
    /**
     * The whole public catalogue in one query, with per-event session totals
     * folded in. 14 companies x 4 events is small, but querying sessions per
     * event would be 56 extra round trips for a page that needs none.
     *
     * Grouping by company happens in PHP - see groupByCompany().
     *
     * @return array<int, array<string, mixed>>
     */
    public function publishedCatalogue(): array
    {
        return Db::select(
            "SELECT e.id, e.title, e.description, e.venue, e.sort_order,
                    e.booking_required, e.external_url,
                    c.id   AS company_id,
                    c.name AS company_name,
                    c.name_kana AS company_kana,
                    COUNT(s.id)                                   AS session_count,
                    COALESCE(SUM(GREATEST(CAST(s.capacity AS SIGNED)
                                        - CAST(s.confirmed_seats AS SIGNED), 0)), 0) AS seats_left,
                    MIN(s.starts_at)                              AS first_starts_at,
                    MAX(s.ends_at)                                AS last_ends_at
             FROM events e
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN event_sessions s
                    ON s.event_id = e.id AND s.status = 'open'
             WHERE e.is_published = 1 AND c.is_published = 1
             GROUP BY e.id, e.title, e.description, e.venue, e.sort_order,
                      e.booking_required, e.external_url,
                      c.id, c.name, c.name_kana
             ORDER BY c.sort_order, c.id, e.sort_order, e.id"
        );
    }

    /**
     * Fold a flat catalogue result into one entry per company.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{id:int, name:string, kana:?string, events:array<int, array<string,mixed>>}>
     */
    public function groupByCompany(array $rows): array
    {
        $companies = [];
        foreach ($rows as $row) {
            $companyId = (int) $row['company_id'];
            if (!isset($companies[$companyId])) {
                $companies[$companyId] = [
                    'id'     => $companyId,
                    'name'   => (string) $row['company_name'],
                    'kana'   => $row['company_kana'] !== null ? (string) $row['company_kana'] : null,
                    'events' => [],
                ];
            }
            $companies[$companyId]['events'][] = $row;
        }
        return array_values($companies);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM events WHERE id = ?', [$id]);
    }

    /** Event joined to its company, which is what every detail page needs. */
    /** @return array<string, mixed>|null */
    public function findWithCompany(int $id, bool $publishedOnly = false): ?array
    {
        $where = $publishedOnly ? 'AND e.is_published = 1 AND c.is_published = 1' : '';
        return Db::selectOne(
            "SELECT e.*, c.name AS company_name, c.id AS company_id
             FROM events e
             JOIN companies c ON c.id = e.company_id
             WHERE e.id = ? {$where}",
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listForAdmin(?int $companyId = null): array
    {
        $sql = "SELECT e.*, c.name AS company_name,
                       (SELECT COUNT(*) FROM event_sessions s WHERE s.event_id = e.id) AS session_count
                FROM events e
                JOIN companies c ON c.id = e.company_id";
        $params = [];
        if ($companyId !== null && $companyId > 0) {
            $sql .= ' WHERE e.company_id = ?';
            $params[] = $companyId;
        }
        $sql .= ' ORDER BY c.sort_order, c.id, e.sort_order, e.id';
        return Db::select($sql, $params);
    }

    public function create(
        int $companyId,
        string $title,
        ?string $description,
        ?string $venue,
        int $sortOrder,
        bool $published,
        bool $bookingRequired = true,
        ?string $externalUrl = null,
        int $maxPartySize = 20,
    ): int {
        Db::execute(
            'INSERT INTO events
               (company_id, title, description, venue, sort_order, is_published,
                booking_required, external_url, max_party_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $companyId, $title, $description, $venue, $sortOrder,
                $published ? 1 : 0, $bookingRequired ? 1 : 0, $externalUrl, $maxPartySize,
            ]
        );
        return Db::lastInsertId();
    }

    /**
     * Full replacement of the editable fields.
     *
     * bookingRequired and externalUrl are required rather than defaulted: an
     * update that omitted them would quietly reset the flag and erase the
     * link, and "forgot to pass it" should be a TypeError, not silent data
     * loss. create() may default them because a new event has nothing to lose.
     */
    public function update(
        int $id,
        int $companyId,
        string $title,
        ?string $description,
        ?string $venue,
        int $sortOrder,
        bool $published,
        bool $bookingRequired,
        ?string $externalUrl,
        int $maxPartySize,
    ): void {
        Db::execute(
            'UPDATE events
             SET company_id = ?, title = ?, description = ?, venue = ?, sort_order = ?,
                 is_published = ?, booking_required = ?, external_url = ?, max_party_size = ?
             WHERE id = ?',
            [
                $companyId, $title, $description, $venue, $sortOrder,
                $published ? 1 : 0, $bookingRequired ? 1 : 0, $externalUrl, $maxPartySize, $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        Db::execute('DELETE FROM events WHERE id = ?', [$id]);
    }

    public function sessionCount(int $eventId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM event_sessions WHERE event_id = ?', [$eventId]);
    }
}
