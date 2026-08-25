<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\ApplicantRepository;
use App\Repository\BookingRepository;
use App\Repository\MailQueueRepository;

/**
 * Promotion from the waitlist. Same lock order as booking and cancellation
 * (applicants -> event_sessions -> bookings); docs/design.md section B.
 *
 * Promotion re-runs the overlap check because time has passed: the person may
 * have booked something else while waiting, and promoting them then would
 * create exactly the double-booking the system exists to prevent. Their own
 * waitlisted row is excluded from that check - it overlaps itself by definition.
 */
final class WaitlistService
{
    public function __construct(
        private readonly ApplicantRepository $applicants = new ApplicantRepository(),
        private readonly BookingRepository $bookings = new BookingRepository(),
        private readonly MailQueueRepository $mailQueue = new MailQueueRepository(),
    ) {
    }

    /**
     * Promote one waitlisted booking to confirmed.
     *
     * @return array<string, mixed> The promoted booking (context row).
     * @throws NotFoundException   unknown booking
     * @throws ValidationException not waitlisted / seats do not fit / overlap
     */
    public function promote(int $bookingId, string $actor): array
    {
        $found = $this->bookings->findById($bookingId);
        if ($found === null) {
            throw new NotFoundException('お探しの申込は見つかりませんでした。');
        }

        $applicantId = (int) $found['applicant_id'];
        $sessionId   = (int) $found['session_id'];

        Db::transaction(function () use ($bookingId, $applicantId, $sessionId, $actor, $found): void {
            // 1) applicant gate, 2) session row, 3) booking - fixed order.
            $this->applicants->lock($applicantId);

            $session = Db::selectOne(
                'SELECT capacity, confirmed_seats, starts_at, ends_at
                 FROM event_sessions WHERE id = ? FOR UPDATE',
                [$sessionId]
            );
            $booking = $this->bookings->lockForUpdate($bookingId);
            if ($session === null || $booking === null) {
                throw new NotFoundException('お探しの申込は見つかりませんでした。');
            }

            // Everything read before the locks is stale; re-verify under them.
            // tryFrom so an unrecognised status is refused rather than raising
            // \ValueError: promoting a booking whose state we cannot read would
            // be the dangerous direction.
            if (BookingStatus::tryFrom((string) $booking['status']) !== BookingStatus::Waitlisted) {
                throw new ValidationException('この申込はキャンセル待ちではありません（既に処理済みの可能性があります）。');
            }

            $partySize = (int) $booking['party_size'];
            $seatsLeft = (int) $session['capacity'] - (int) $session['confirmed_seats'];
            if ($partySize > $seatsLeft) {
                throw new ValidationException(
                    "空席が足りません（この申込は {$partySize} 名、空きは {$seatsLeft} 名分）。"
                );
            }

            // The wait may have been long; the person may have booked another
            // event in this time slot since. Their own row is excluded.
            $conflict = $this->bookings->findOverlapping(
                $applicantId,
                (string) $session['starts_at'],
                (string) $session['ends_at'],
                $bookingId
            );
            if ($conflict !== null) {
                throw new ValidationException(sprintf(
                    '繰り上げできません。この方は時間帯の重なる予約を既に持っています（%s「%s」）。',
                    (string) $conflict['company_name'],
                    (string) $conflict['event_title']
                ));
            }

            // Travel buffer, blocking mode only: promoting someone into a slot
            // they cannot physically reach is the same mistake as booking it.
            // In warn mode there is nobody to show a popup to (the applicant
            // is not present), so promotion follows the applicant's original,
            // warned-and-accepted choice.
            if (BookingService::travelBufferBlocks()) {
                $near = $this->bookings->findWithinTravelBuffer(
                    $applicantId,
                    (string) $session['starts_at'],
                    (string) $session['ends_at'],
                    BookingService::travelBufferMinutes(),
                    $bookingId
                );
                if ($near !== null) {
                    throw new ValidationException(sprintf(
                        '繰り上げできません。移動時間を考慮すると間に合いません（%s「%s」との間隔が短すぎます）。',
                        (string) $near['company_name'],
                        (string) $near['event_title']
                    ));
                }
            }

            Db::execute(
                'UPDATE event_sessions SET confirmed_seats = confirmed_seats + ? WHERE id = ?',
                [$partySize, $sessionId]
            );
            // waitlist_seq goes NULL: only waitlisted rows carry a queue number
            // (and the E-4 invariant checks exactly that).
            Db::execute(
                "UPDATE bookings
                 SET status = 'confirmed', confirmed_at = NOW(), waitlist_seq = NULL
                 WHERE id = ?",
                [$bookingId]
            );

            $this->bookings->logEvent(
                $bookingId,
                BookingStatus::Waitlisted->value,
                BookingStatus::Confirmed->value,
                $actor
            );
            $this->enqueuePromotionMail($found);
        });

        return $found;
    }

    /**
     * Promote the best-fitting waitlisted booking on a session: the OLDEST
     * (lowest waitlist_seq) whose party_size fits the free seats. A group too
     * large for the gap is passed over rather than blocking everyone behind it.
     *
     * The selection here is deliberately made WITHOUT locks. The fixed lock
     * order starts at applicants, and which applicant to lock is only known
     * once a candidate is chosen - locking event_sessions first to read the
     * free seats and then applicants would run the order backwards and
     * reintroduce the deadlock the ordering exists to prevent. So this method
     * nominates a candidate from an unlocked read, and promote() re-verifies
     * everything under the properly ordered locks: still waitlisted, still
     * fits the seats as they are NOW, no overlapping booking acquired while
     * waiting. If the world changed in between, the candidate is excluded and
     * selection runs again; each pass either promotes or permanently excludes
     * one candidate, so the loop is bounded by the queue length.
     *
     * @return array<string, mixed>|null The promoted booking, or null when
     *                                   nobody in the queue fits the free seats.
     */
    public function promoteNextFitting(int $sessionId, string $actor): ?array
    {
        $excluded = [];

        while (true) {
            // Advisory reads: promote() is the authority, under locks.
            $free = (int) Db::scalar(
                'SELECT CAST(capacity AS SIGNED) - CAST(confirmed_seats AS SIGNED)
                 FROM event_sessions WHERE id = ?',
                [$sessionId]
            );
            if ($free <= 0) {
                return null;
            }

            $notIn  = $excluded === [] ? '' : 'AND id NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
            $params = array_merge([$sessionId, $free], $excluded);
            $candidate = Db::selectOne(
                "SELECT id FROM bookings
                 WHERE session_id = ? AND status = 'waitlisted' AND party_size <= ?
                 {$notIn}
                 ORDER BY waitlist_seq
                 LIMIT 1",
                $params
            );
            if ($candidate === null) {
                return null;
            }

            try {
                return $this->promote((int) $candidate['id'], $actor);
            } catch (ValidationException) {
                // Lost a race (someone else took the seats or the booking) or
                // the candidate now holds an overlapping booking. Either way
                // this candidate is out; the next pass re-reads the seats and
                // tries the next-oldest fit.
                $excluded[] = (int) $candidate['id'];
            }
        }
    }

    /**
     * Automatic promotion, run after a cancellation when waitlist.auto_promote
     * is on: repeat the first-fit selection until the remaining gap fits
     * nobody. Oldest-first among those who fit - a large group at the head
     * does not block a smaller, older-than-everyone-else party behind it,
     * but nobody younger jumps a candidate who would also have fit.
     */
    public function autoPromote(int $sessionId): int
    {
        $promoted = 0;
        while ($this->promoteNextFitting($sessionId, 'system:auto_promote') !== null) {
            $promoted++;
        }
        return $promoted;
    }

    /** @param array<string, mixed> $found Context row of the promoted booking. */
    private function enqueuePromotionMail(array $found): void
    {
        $when = jp_datetime((string) $found['starts_at']) . '〜' . jp_time((string) $found['ends_at']);
        $manageNote = 'お申し込み内容の確認・キャンセルは、申込時にお送りしたメールに記載のURLから行えます。';

        $body = <<<TEXT
        {$found['name']} 様

        キャンセル待ちでお申し込みいただいていた以下の回に空きが出たため、
        ご参加が確定しました。

        ── 確定した内容 ────────────
        イベント　: {$found['event_title']}
        主催　　　: {$found['company_name']}
        日時　　　: {$when}
        人数　　　: {$found['party_size']} 名
        予約番号　: {$found['reference_code']}
        ────────────────────

        当日のご来場をお待ちしています。
        {$manageNote}
        TEXT;

        $this->mailQueue->enqueue(
            (string) $found['email'],
            (string) $found['name'],
            "【イベント予約】繰り上げのご案内：ご参加が確定しました（{$found['event_title']}）",
            $body,
            (int) $found['id']
        );
    }
}
