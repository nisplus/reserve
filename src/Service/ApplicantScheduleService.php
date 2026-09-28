<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ScheduleClash;
use App\Repository\BookingRepository;

/**
 * What else the people on an admin list have booked that day.
 *
 * The office and the company staff need this at one moment in particular:
 * before pressing 繰り上げ. Promotion turns a maybe into a commitment, and the
 * transaction will refuse the impossible cases (WaitlistService::promote) -
 * but only after the button is pressed, and only for a true overlap. The
 * near-miss, where a promoted booking ends five minutes before another one
 * starts across the site, is refused by nothing in the current configuration
 * and shown by nothing at all.
 *
 * Two deliberate limits:
 *
 *   - SAME DAY ONLY. Touring several companies in one day is what this site is
 *     for, so a booking next month is not context, it is noise.
 *   - NO PERSONAL DATA. The rows come back with the event, the company and the
 *     times, and nothing else. The other company's programme is public
 *     knowledge, but the names, contacts and notes attached to its bookings
 *     are not this screen's business - so they are left out of the query
 *     rather than left out of the template.
 */
final class ApplicantScheduleService
{
    public function __construct(
        private readonly BookingRepository $bookings = new BookingRepository(),
    ) {
    }

    /**
     * Same-day companions of each booking on a page, keyed by booking id.
     *
     * One query for the whole page, in the shape BookingAttendeeRepository
     * already uses: a list of 50 bookings must not become 50 queries.
     *
     * @param array<int, array<string, mixed>> $rows Admin list rows; each needs
     *        id, applicant_id, starts_at, ends_at and company_id.
     * @return array<int, array{clash: ScheduleClash|null, others: array<int, array<string, mixed>>}>
     */
    public function forRows(array $rows): array
    {
        $applicantIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['applicant_id'] ?? 0);
            if ($id > 0) {
                $applicantIds[$id] = $id;
            }
        }
        if ($applicantIds === []) {
            return [];
        }

        $byApplicant = [];
        foreach ($this->bookings->liveForApplicants(array_values($applicantIds)) as $booking) {
            $byApplicant[(int) $booking['applicant_id']][] = $booking;
        }

        $buffer = BookingService::travelBufferMinutes();

        $out = [];
        foreach ($rows as $row) {
            $bookingId = (int) $row['id'];
            $applicantId = (int) ($row['applicant_id'] ?? 0);

            $others = [];
            $worst = null;
            foreach ($byApplicant[$applicantId] ?? [] as $other) {
                if ((int) $other['id'] === $bookingId) {
                    continue; // itself
                }
                if (substr((string) $other['starts_at'], 0, 10) !== substr((string) $row['starts_at'], 0, 10)) {
                    continue; // another day
                }

                $clash = $this->classify($row, $other, $buffer);
                $other['clash'] = $clash;
                $others[] = $other;

                if ($worst === null
                    || ($clash === ScheduleClash::Overlap)
                    || ($clash === ScheduleClash::Tight && $worst === ScheduleClash::SameDay)
                ) {
                    $worst = $clash;
                }
            }

            if ($others === []) {
                continue;
            }

            // Earliest first: the operator is reading someone's day.
            usort(
                $others,
                static fn (array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at'])
            );

            $out[$bookingId] = ['clash' => $worst, 'others' => $others];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row   The booking being looked at.
     * @param array<string, mixed> $other Another booking of the same person.
     */
    private function classify(array $row, array $other, int $bufferMinutes): ScheduleClash
    {
        $start = strtotime((string) $row['starts_at']);
        $end   = strtotime((string) $row['ends_at']);
        $otherStart = strtotime((string) $other['starts_at']);
        $otherEnd   = strtotime((string) $other['ends_at']);

        // Half-open, exactly as BookingRepository::findOverlapping compares:
        // back-to-back sessions do not overlap.
        if ($otherStart < $end && $start < $otherEnd) {
            return ScheduleClash::Overlap;
        }

        // Nowhere to travel to between two sessions of the same company.
        if ((int) $other['company_id'] === (int) $row['company_id']) {
            return ScheduleClash::SameDay;
        }

        $gap = $otherStart >= $end
            ? (int) (($otherStart - $end) / 60)
            : (int) (($start - $otherEnd) / 60);

        // "15分以下" - the boundary is inclusive, as it is when booking.
        return $bufferMinutes > 0 && $gap <= $bufferMinutes
            ? ScheduleClash::Tight
            : ScheduleClash::SameDay;
    }

    /**
     * One line for a flash message, or null when nothing needs saying.
     *
     * Used right after a promotion: by then the seat is taken, so this is not
     * a warning to act on but a record that the office saw it.
     *
     * @param array<string, mixed> $row A booking with id, applicant_id, times, company_id.
     */
    public function noteFor(array $row): ?string
    {
        $found = $this->forRows([$row])[(int) $row['id']] ?? null;
        if ($found === null || $found['clash'] === null || !$found['clash']->needsAttention()) {
            return null;
        }

        $parts = [];
        foreach ($found['others'] as $other) {
            if (!$other['clash']->needsAttention()) {
                continue;
            }
            $parts[] = sprintf(
                '%s「%s」%s〜%s（%s）',
                (string) $other['company_name'],
                (string) $other['event_title'],
                jp_time((string) $other['starts_at']),
                jp_time((string) $other['ends_at']),
                $other['clash']->label()
            );
        }

        return $parts === []
            ? null
            : 'この方は同じ日に ' . implode('、', $parts) . ' のご予約もお持ちです。ご確認ください。';
    }
}
