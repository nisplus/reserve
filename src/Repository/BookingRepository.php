<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

final class BookingRepository
{
    /**
     * Live bookings by this applicant whose time range overlaps [startsAt, endsAt).
     *
     * Strict `<` on both sides makes the interval half-open: back-to-back slots
     * (10:00-10:45 and 10:45-11:30) do not overlap and may both be booked.
     *
     * waitlisted counts as a conflict on purpose: if it did not, a person could
     * hold a confirmed seat and a waitlist place at the same hour, and promoting
     * them later would create the very overlap this check exists to prevent.
     *
     * Only meaningful inside a transaction while holding the applicant lock -
     * without it, a concurrent booking could commit between check and insert.
     *
     * @return array<string, mixed>|null The earliest conflicting booking, with
     *                                   event context for the error message.
     */
    public function findOverlapping(int $applicantId, string $startsAt, string $endsAt): ?array
    {
        return Db::selectOne(
            "SELECT b.id, b.session_id, b.status, s.starts_at, s.ends_at,
                    e.title AS event_title, c.name AS company_name
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             JOIN companies c      ON c.id = e.company_id
             WHERE b.applicant_id = ?
               AND b.status IN ('confirmed', 'waitlisted')
               AND s.starts_at < ?
               AND ? < s.ends_at
             ORDER BY s.starts_at
             LIMIT 1",
            [$applicantId, $endsAt, $startsAt]
        );
    }

    /**
     * @param array{
     *   reference_code: string, session_id: int, applicant_id: int,
     *   email: string, name: string, party_size: int, status: string,
     *   waitlist_seq: int|null, cancel_token_hash: string, confirmed: bool,
     * } $row
     */
    public function insert(array $row): int
    {
        Db::execute(
            'INSERT INTO bookings
               (reference_code, session_id, applicant_id, email, name, party_size,
                status, waitlist_seq, cancel_token_hash, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($row['confirmed'] ? 'NOW()' : 'NULL') . ')',
            [
                $row['reference_code'],
                $row['session_id'],
                $row['applicant_id'],
                $row['email'],
                $row['name'],
                $row['party_size'],
                $row['status'],
                $row['waitlist_seq'],
                $row['cancel_token_hash'],
            ]
        );
        return Db::lastInsertId();
    }

    /** Append to the audit trail. Always called in the same transaction as the change. */
    public function logEvent(int $bookingId, ?string $fromStatus, string $toStatus, string $actor, ?string $note = null): void
    {
        Db::execute(
            'INSERT INTO booking_events (booking_id, from_status, to_status, actor, note) VALUES (?, ?, ?, ?, ?)',
            [$bookingId, $fromStatus, $toStatus, $actor, $note]
        );
    }

    /**
     * Booking with its event context, addressed by the public reference code.
     *
     * waitlist_position counts live waitlist entries at or before this one, so
     * it shrinks as people ahead cancel or get promoted - unlike waitlist_seq,
     * which is a permanent ticket number.
     *
     * @return array<string, mixed>|null
     */
    public function findByReference(string $referenceCode): ?array
    {
        return Db::selectOne(
            "SELECT b.id, b.reference_code, b.session_id, b.email, b.name, b.party_size,
                    b.status, b.waitlist_seq, b.created_at, b.confirmed_at, b.cancelled_at,
                    s.starts_at, s.ends_at,
                    e.title AS event_title, e.venue,
                    c.name AS company_name,
                    (SELECT COUNT(*) FROM bookings w
                      WHERE w.session_id = b.session_id
                        AND w.status = 'waitlisted'
                        AND w.waitlist_seq <= b.waitlist_seq) AS waitlist_position
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             JOIN companies c      ON c.id = e.company_id
             WHERE b.reference_code = ?",
            [$referenceCode]
        );
    }
}
