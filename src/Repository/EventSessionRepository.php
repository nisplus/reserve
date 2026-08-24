<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

final class EventSessionRepository
{
    /**
     * Columns every screen wants, with seats_left computed server-side.
     *
     * capacity and confirmed_seats are both SMALLINT UNSIGNED, so the
     * subtraction is cast to SIGNED first: an unsigned subtraction that goes
     * negative wraps to an enormous number instead of going below zero. It
     * should never go negative, but a display query is the wrong place to find
     * that out.
     */
    private const SELECT_LIST = "s.id, s.event_id, s.starts_at, s.ends_at, s.session_date,
            s.capacity, s.confirmed_seats, s.waitlist_counter, s.status,
            GREATEST(CAST(s.capacity AS SIGNED) - CAST(s.confirmed_seats AS SIGNED), 0) AS seats_left";

    /** @return array<int, array<string, mixed>> */
    public function forEvent(int $eventId, bool $openOnly = false): array
    {
        $where = $openOnly ? "AND s.status = 'open'" : '';
        return Db::select(
            'SELECT ' . self::SELECT_LIST . ",
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.session_id = s.id AND b.status = 'waitlisted') AS waitlist_count
             FROM event_sessions s
             WHERE s.event_id = ? {$where}
             ORDER BY s.starts_at, s.id",
            [$eventId]
        );
    }

    /**
     * Group a session list by calendar day for display.
     *
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array{date:string, sessions:array<int, array<string,mixed>>}>
     */
    public function groupByDate(array $sessions): array
    {
        $days = [];
        foreach ($sessions as $session) {
            $date = (string) $session['session_date'];
            $days[$date]['date'] ??= $date;
            $days[$date]['sessions'][] = $session;
        }
        return array_values($days);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::selectOne('SELECT ' . self::SELECT_LIST . ' FROM event_sessions s WHERE s.id = ?', [$id]);
    }

    /** Session plus its event and company. Used by every booking screen. */
    /** @return array<string, mixed>|null */
    public function findWithContext(int $id, bool $publishedOnly = false): ?array
    {
        $where = $publishedOnly ? 'AND e.is_published = 1 AND c.is_published = 1' : '';
        return Db::selectOne(
            'SELECT ' . self::SELECT_LIST . ",
                    e.id AS event_id, e.title AS event_title, e.venue, e.description,
                    c.id AS company_id, c.name AS company_name
             FROM event_sessions s
             JOIN events e    ON e.id = s.event_id
             JOIN companies c ON c.id = e.company_id
             WHERE s.id = ? {$where}",
            [$id]
        );
    }

    public function create(int $eventId, string $startsAt, string $endsAt, int $capacity, string $status): int
    {
        Db::execute(
            'INSERT INTO event_sessions (event_id, starts_at, ends_at, capacity, status) VALUES (?, ?, ?, ?, ?)',
            [$eventId, $startsAt, $endsAt, $capacity, $status]
        );
        return Db::lastInsertId();
    }

    /**
     * Capacity and schedule changes only; seat counters belong to the booking
     * transaction and are never written from here.
     */
    public function update(int $id, string $startsAt, string $endsAt, int $capacity, string $status): void
    {
        Db::execute(
            'UPDATE event_sessions SET starts_at = ?, ends_at = ?, capacity = ?, status = ? WHERE id = ?',
            [$startsAt, $endsAt, $capacity, $status, $id]
        );
    }

    public function delete(int $id): void
    {
        Db::execute('DELETE FROM event_sessions WHERE id = ?', [$id]);
    }

    public function bookingCount(int $sessionId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM bookings WHERE session_id = ? AND status <> 'cancelled'",
            [$sessionId]
        );
    }

    /**
     * Any bookings at all, cancelled included. The FK is ON DELETE RESTRICT,
     * so even a cancelled booking blocks deletion - by design: bookings are
     * the audit trail of who applied, and deleting a session must not be a
     * way to shred it.
     */
    public function hasAnyBookings(int $sessionId): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM bookings WHERE session_id = ?',
            [$sessionId]
        ) > 0;
    }

    /** Does another session of this event already start at this instant? */
    public function startExists(int $eventId, string $startsAt, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM event_sessions WHERE event_id = ? AND starts_at = ?';
        $params = [$eventId, $startsAt];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return (int) Db::scalar($sql, $params) > 0;
    }

    /**
     * Sessions with someone waiting and room to seat them. Drives the
     * "promotion candidates" badge on the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function withPromotableWaitlist(?int $companyId = null): array
    {
        $scope  = $companyId !== null ? 'AND e.company_id = ?' : '';
        $params = $companyId !== null ? [$companyId] : [];

        return Db::select(
            'SELECT ' . self::SELECT_LIST . ",
                    e.title AS event_title, c.name AS company_name,
                    (SELECT COUNT(*) FROM bookings b
                      WHERE b.session_id = s.id AND b.status = 'waitlisted') AS waitlist_count
             FROM event_sessions s
             JOIN events e    ON e.id = s.event_id
             JOIN companies c ON c.id = e.company_id
             WHERE s.confirmed_seats < s.capacity
               AND EXISTS (SELECT 1 FROM bookings b
                            WHERE b.session_id = s.id AND b.status = 'waitlisted')
               {$scope}
             ORDER BY s.starts_at, s.id",
            $params
        );
    }
}
