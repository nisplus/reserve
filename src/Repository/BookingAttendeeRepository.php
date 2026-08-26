<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

/**
 * Who is actually coming, one row per person.
 *
 * attendee_no 1 is the applicant (their name copied from bookings.name), 2..N
 * the people they are bringing. Storing the applicant here as well means an
 * attendee list is one query instead of "the booking's name, then these
 * others" at every screen that shows one.
 *
 * The table records the names that are KNOWN, which is not always party_size
 * rows: the web form demands every name, but BookingService accepts a booking
 * without them so CLI callers and the concurrency harness are not forced to
 * invent people. Callers that display a list must therefore cope with it
 * being shorter than party_size.
 */
final class BookingAttendeeRepository
{
    /**
     * Replace the attendee list for a booking. Called inside the booking
     * transaction, so a rolled-back booking takes its attendees with it.
     *
     * @param array<int, string> $names Ordered from attendee_no 1.
     */
    public function replaceFor(int $bookingId, array $names): void
    {
        Db::execute('DELETE FROM booking_attendees WHERE booking_id = ?', [$bookingId]);

        $attendeeNo = 1;
        foreach ($names as $name) {
            $trimmed = trim($name);
            if ($trimmed === '') {
                $attendeeNo++;
                continue; // a blank slot is simply unknown, not an empty person
            }
            Db::execute(
                'INSERT INTO booking_attendees (booking_id, attendee_no, name) VALUES (?, ?, ?)',
                [$bookingId, $attendeeNo, mb_substr($trimmed, 0, 100)]
            );
            $attendeeNo++;
        }
    }

    /** @return array<int, string> Names in attendee_no order. */
    public function namesFor(int $bookingId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            Db::select(
                'SELECT name FROM booking_attendees WHERE booking_id = ? ORDER BY attendee_no',
                [$bookingId]
            )
        );
    }

    /**
     * Names for many bookings at once, for the admin list and CSV export -
     * one query rather than one per row.
     *
     * @param array<int, int> $bookingIds
     * @return array<int, array<int, string>> booking id => names
     */
    public function namesForMany(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));

        $grouped = [];
        foreach (Db::select(
            "SELECT booking_id, name FROM booking_attendees
             WHERE booking_id IN ({$placeholders})
             ORDER BY booking_id, attendee_no",
            $bookingIds
        ) as $row) {
            $grouped[(int) $row['booking_id']][] = (string) $row['name'];
        }
        return $grouped;
    }
}
