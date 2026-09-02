<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;
use App\Domain\BookingStatus;

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
    public function findOverlapping(int $applicantId, string $startsAt, string $endsAt, ?int $excludeBookingId = null): ?array
    {
        // The exclusion exists for promotion: the waitlisted booking being
        // promoted overlaps its own session by definition and must not count
        // as its own conflict.
        $exclude = $excludeBookingId !== null ? 'AND b.id <> ?' : '';
        $params  = [$applicantId, $endsAt, $startsAt];
        if ($excludeBookingId !== null) {
            $params[] = $excludeBookingId;
        }

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
               {$exclude}
             ORDER BY s.starts_at
             LIMIT 1",
            $params
        );
    }

    /**
     * A live booking this applicant already holds for the same EVENT, on any
     * of its sessions.
     *
     * Distinct from findOverlapping, which only sees clashing clock times: two
     * sessions of one event never overlap each other, so without this an
     * applicant could take the 10:00 tour and the 14:00 tour of the same event.
     * One booking per person per event is the rule; a party brings people
     * along through party_size, not through a second booking.
     *
     * Only meaningful inside the transaction while holding the applicant lock.
     *
     * @param int|null $excludeBookingId Skip this row - used by promotion,
     *                                   where the candidate is its own match.
     * @return array<string, mixed>|null
     */
    public function findSameEvent(int $applicantId, int $eventId, ?int $excludeBookingId = null): ?array
    {
        $exclude = $excludeBookingId !== null ? 'AND b.id <> ?' : '';
        $params  = [$applicantId, $eventId];
        if ($excludeBookingId !== null) {
            $params[] = $excludeBookingId;
        }

        return Db::selectOne(
            "SELECT b.id, b.session_id, b.status, s.starts_at, s.ends_at,
                    e.title AS event_title, c.name AS company_name
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             JOIN companies c      ON c.id = e.company_id
             WHERE b.applicant_id = ?
               AND s.event_id = ?
               AND b.status IN ('confirmed', 'waitlisted')
               {$exclude}
             ORDER BY s.starts_at
             LIMIT 1",
            $params
        );
    }

    /**
     * Live bookings that do NOT overlap [startsAt, endsAt) but come within
     * $bufferMinutes of it on either side - the "can they physically get
     * there" check. Gap boundaries are inclusive: a gap of exactly
     * $bufferMinutes still matches ("15分以下"), which also means back-to-back
     * slots (gap 0) match even though the overlap rule deliberately allows them.
     *
     * True overlaps are excluded here because they are a different answer:
     * the caller reports those as duplicates, not as travel-time problems.
     *
     * Advisory on the confirmation screen; authoritative only when called
     * inside the booking transaction under the applicant lock.
     *
     * @param int|null $exemptCompanyId Ignore bookings hosted by this company.
     *        Passed the company of the session being booked, so back-to-back
     *        events of one host never count as a travel problem - there is
     *        nowhere to travel to. Without it a company running consecutive
     *        sessions in the same building would warn about every pair.
     * @return array<string, mixed>|null The nearest such booking, with event context.
     */
    public function findWithinTravelBuffer(
        int $applicantId,
        string $startsAt,
        string $endsAt,
        int $bufferMinutes,
        ?int $excludeBookingId = null,
        ?int $exemptCompanyId = null,
    ): ?array {
        $exclude = $excludeBookingId !== null ? 'AND b.id <> ?' : '';
        $sameHost = $exemptCompanyId !== null ? 'AND c.id <> ?' : '';
        $params  = [$applicantId, $endsAt, $bufferMinutes, $startsAt, $bufferMinutes, $endsAt, $startsAt];
        if ($excludeBookingId !== null) {
            $params[] = $excludeBookingId;
        }
        if ($exemptCompanyId !== null) {
            $params[] = $exemptCompanyId;
        }

        return Db::selectOne(
            "SELECT b.id, b.session_id, b.status, s.starts_at, s.ends_at,
                    e.title AS event_title, c.name AS company_name
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             JOIN companies c      ON c.id = e.company_id
             WHERE b.applicant_id = ?
               AND b.status IN ('confirmed', 'waitlisted')
               AND s.starts_at <= DATE_ADD(?, INTERVAL ? MINUTE)
               AND ? <= DATE_ADD(s.ends_at, INTERVAL ? MINUTE)
               AND NOT (s.starts_at < ? AND ? < s.ends_at)
               {$exclude}
               {$sameHost}
             ORDER BY s.starts_at
             LIMIT 1",
            $params
        );
    }

    /**
     * Insert a new booking.
     *
     * Named parameters rather than an array: the previous shape carried a
     * 'confirmed' key that looked like a column but was really an instruction
     * about confirmed_at, which reads as a schema mismatch every time someone
     * checks it against 001_init.sql. It also allowed the two to disagree -
     * status 'confirmed' with confirmed_at NULL is a row that breaks
     * invariant (5) in docs/design.md E-4.
     *
     * confirmed_at is now derived from $status here, so that pairing cannot be
     * got wrong by a caller. Callers should pass arguments by name; several
     * neighbouring parameters are strings and position is easy to muddle.
     */
    public function insert(
        string $referenceCode,
        int $sessionId,
        int $applicantId,
        string $email,
        string $name,
        int $partySize,
        BookingStatus $status,
        ?int $waitlistSeq,
        string $cancelTokenHash,
        ?string $phone = null,
        ?string $message = null,
    ): int {
        // NOW() is server-side and takes the session time zone (+09:00), which
        // is what the DATETIME columns hold. It is a literal, not input.
        $confirmedAt = $status === BookingStatus::Confirmed ? 'NOW()' : 'NULL';

        Db::execute(
            'INSERT INTO bookings
               (reference_code, session_id, applicant_id, email, phone, name, message,
                party_size, status, waitlist_seq, cancel_token_hash, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . $confirmedAt . ')',
            [
                $referenceCode,
                $sessionId,
                $applicantId,
                $email,
                $phone,
                $name,
                $message,
                $partySize,
                $status->value,
                $waitlistSeq,
                $cancelTokenHash,
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
        return $this->findWithContext('b.reference_code = ?', $referenceCode);
    }

    /**
     * Same row, addressed by the hashed cancel token from a /manage URL.
     * Non-locking on purpose: this is how the cancel flow finds out which
     * applicant and session rows to lock, and taking the booking lock first
     * would invert the fixed lock order.
     *
     * @return array<string, mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        return $this->findWithContext('b.cancel_token_hash = ?', $tokenHash);
    }

    /**
     * Re-read status and seat count under an exclusive lock, third in the lock
     * order (applicants -> event_sessions -> bookings). The caller must compare
     * this against what it saw before locking: two concurrent cancels both pass
     * the unlocked read, and only this re-check keeps the second one from
     * returning the seats twice (an unsigned counter would wrap, not go negative).
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return Db::selectOne(
            'SELECT id, session_id, applicant_id, status, party_size, waitlist_seq
             FROM bookings WHERE id = ? FOR UPDATE',
            [$id]
        );
    }

    /** Same context row by primary key; the admin screens address rows this way. */
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->findWithContext('b.id = ?', (string) $id);
    }

    /**
     * Admin list search. $filters keys: company_id, event_id, session_id,
     * status, email (substring). All optional; unknown keys are ignored -
     * every fragment below is bound, nothing is interpolated.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchForAdmin(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->adminFilterWhere($filters);

        $sql = "SELECT b.id, b.reference_code, b.email, b.phone, b.name, b.message,
                       b.party_size, b.status, b.waitlist_seq, b.created_at, b.cancelled_at,
                       s.id AS session_id, s.starts_at, s.ends_at,
                       s.capacity, s.confirmed_seats,
                       e.id AS event_id, e.title AS event_title,
                       c.id AS company_id, c.name AS company_name
                FROM bookings b
                JOIN event_sessions s ON s.id = b.session_id
                JOIN events e         ON e.id = s.event_id
                JOIN companies c      ON c.id = e.company_id
                {$where}
                ORDER BY b.created_at DESC, b.id DESC
                LIMIT ? OFFSET ?";

        // Native prepares refuse string-typed LIMIT/OFFSET, so bind by hand.
        $statement = Db::pdo()->prepare($sql);
        $position = 1;
        foreach ($params as $value) {
            $statement->bindValue($position++, $value);
        }
        $statement->bindValue($position++, $limit, \PDO::PARAM_INT);
        $statement->bindValue($position, $offset, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filters */
    public function countForAdmin(array $filters): int
    {
        [$where, $params] = $this->adminFilterWhere($filters);
        return (int) Db::scalar(
            "SELECT COUNT(*)
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             JOIN companies c      ON c.id = e.company_id
             {$where}",
            $params
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function adminFilterWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (['company_id' => 'c.id', 'event_id' => 'e.id', 'session_id' => 's.id'] as $key => $column) {
            if ((int) ($filters[$key] ?? 0) > 0) {
                $conditions[] = "{$column} = ?";
                $params[] = (int) $filters[$key];
            }
        }
        // Status values come from the controller's whitelist, but bind anyway.
        if (($filters['status'] ?? '') !== '') {
            $conditions[] = 'b.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (($filters['email'] ?? '') !== '') {
            $conditions[] = 'b.email LIKE ?';
            // Escape LIKE wildcards so a search for "100%" means the literal text.
            $params[] = '%' . addcslashes((string) $filters['email'], '%_\\') . '%';
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array<string, mixed>|null */
    private function findWithContext(string $where, string $param): ?array
    {
        return Db::selectOne(
            "SELECT b.id, b.reference_code, b.session_id, b.applicant_id, b.email, b.phone,
                    b.name, b.message, b.party_size, b.status, b.waitlist_seq, b.created_at,
                    b.confirmed_at, b.cancelled_at,
                    s.starts_at, s.ends_at,
                    e.id AS event_id, e.title AS event_title, e.venue,
                    -- company_id is what Authz checks a write operation against.
                    c.id AS company_id, c.name AS company_name,
                    (SELECT COUNT(*) FROM bookings w
                      WHERE w.session_id = b.session_id
                        AND w.status = 'waitlisted'
                        AND w.waitlist_seq <= b.waitlist_seq) AS waitlist_position
             FROM bookings b
             JOIN event_sessions s ON s.id = b.session_id
             JOIN events e         ON e.id = s.event_id
             JOIN companies c      ON c.id = e.company_id
             WHERE {$where}",
            [$param]
        );
    }
}
