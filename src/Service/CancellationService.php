<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Db;
use App\Domain\BookingStatus;
use App\Exception\NotFoundException;
use App\Repository\ApplicantRepository;
use App\Repository\BookingRepository;
use App\Repository\MailQueueRepository;

/**
 * Self-service cancellation via the token from the confirmation e-mail.
 *
 * Takes the same locks in the same order as BookingService
 * (applicants -> event_sessions -> bookings); see docs/design.md section B.
 * The subtlety unique to this path: the row is found by token BEFORE any lock
 * is taken, so by the time the locks are held the booking may have changed -
 * another tab may have cancelled it already. Hence the re-read under lock, and
 * the idempotent early exit. Without it, two concurrent cancels would each
 * subtract party_size from confirmed_seats, and the unsigned column would wrap
 * to ~65000 instead of going negative.
 */
final class CancellationService
{
    public function __construct(
        private readonly ApplicantRepository $applicants = new ApplicantRepository(),
        private readonly BookingRepository $bookings = new BookingRepository(),
        private readonly MailQueueRepository $mailQueue = new MailQueueRepository(),
    ) {
    }

    /**
     * Cancel the booking behind a raw /manage token (the applicant's own action).
     *
     * @return array{already_cancelled: bool, was: BookingStatus, auto_promoted: int}
     * @throws NotFoundException unknown token
     */
    public function cancelByToken(string $rawToken): array
    {
        $found = $this->bookings->findByTokenHash(TokenService::hashToken($rawToken));
        if ($found === null) {
            throw new NotFoundException('お探しの予約は見つかりませんでした。URLをお確かめください。');
        }
        return $this->cancel($found, 'applicant', byAdmin: false);
    }

    /**
     * Cancel on the applicant's behalf, from the admin screen.
     *
     * @return array{already_cancelled: bool, was: BookingStatus, auto_promoted: int}
     * @throws NotFoundException unknown booking
     */
    public function cancelById(int $bookingId, string $actor): array
    {
        $found = $this->bookings->findById($bookingId);
        if ($found === null) {
            throw new NotFoundException('お探しの申込は見つかりませんでした。');
        }
        return $this->cancel($found, $actor, byAdmin: true);
    }

    /**
     * The shared cancel transaction. Cancelling an already-cancelled booking
     * reports success without touching the seats: to whoever clicks the button
     * twice, the second click worked just as well as the first.
     *
     * @param array<string, mixed> $found Context row located WITHOUT locks - it
     *                                    only tells us which rows to lock;
     *                                    status is re-read under lock below.
     * @return array{already_cancelled: bool, was: BookingStatus, auto_promoted: int}
     */
    private function cancel(array $found, string $actor, bool $byAdmin): array
    {
        $applicantId = (int) $found['applicant_id'];
        $sessionId   = (int) $found['session_id'];
        $bookingId   = (int) $found['id'];

        $result = Db::transaction(function () use ($applicantId, $sessionId, $bookingId, $found, $actor, $byAdmin): array {
            // 1) Applicant gate - same first lock as booking, so a cancel and
            //    a new application by the same person serialise cleanly.
            $this->applicants->lock($applicantId);

            // 2) Session row: seat accounting happens only under this lock.
            $session = Db::selectOne(
                'SELECT capacity, confirmed_seats FROM event_sessions WHERE id = ? FOR UPDATE',
                [$sessionId]
            );

            // 3) The booking itself, re-read under lock. THIS is the check
            //    that makes double-cancel safe; everything read before the
            //    locks is stale by definition.
            $booking = $this->bookings->lockForUpdate($bookingId);
            if ($booking === null || $session === null) {
                throw new NotFoundException('お探しの予約は見つかりませんでした。');
            }

            $was = BookingStatus::from((string) $booking['status']);
            if ($was === BookingStatus::Cancelled) {
                return ['already_cancelled' => true, 'was' => $was];
            }

            // 4) Seats go back only for a confirmed booking; a waitlisted one
            //    never held any. waitlist_seq is cleared either way - gaps in
            //    the sequence are fine, duplicates are not.
            if ($was === BookingStatus::Confirmed) {
                Db::execute(
                    'UPDATE event_sessions SET confirmed_seats = confirmed_seats - ? WHERE id = ?',
                    [(int) $booking['party_size'], $sessionId]
                );
            }
            Db::execute(
                "UPDATE bookings
                 SET status = 'cancelled', cancelled_at = NOW(), waitlist_seq = NULL
                 WHERE id = ?",
                [$bookingId]
            );

            // 5) Audit trail + outbox, all inside the transaction.
            $this->bookings->logEvent($bookingId, $was->value, BookingStatus::Cancelled->value, $actor);
            $this->enqueueCancellationMail($found, $was, $byAdmin);

            // An admin cancelling already knows a seat came free; the vacancy
            // notice is for cancellations that happen without one watching.
            if ($was === BookingStatus::Confirmed && !$byAdmin) {
                $this->notifyAdminOfVacancy($sessionId, $found, (int) $booking['party_size']);
            }

            return ['already_cancelled' => false, 'was' => $was];
        });

        // After commit: with auto_promote on, a freed seat goes straight to the
        // head of the queue. Separate transactions on purpose - a promotion
        // failure must not roll back the cancellation that freed the seat.
        $result['auto_promoted'] = 0;
        if (!$result['already_cancelled']
            && $result['was'] === BookingStatus::Confirmed
            && Config::bool('waitlist.auto_promote')
        ) {
            $result['auto_promoted'] = (new WaitlistService())->autoPromote($sessionId);
        }

        return $result;
    }

    /** Confirmation to the applicant that the cancellation went through. */
    private function enqueueCancellationMail(array $found, BookingStatus $was, bool $byAdmin): void
    {
        $when = jp_datetime((string) $found['starts_at']) . '〜' . jp_time((string) $found['ends_at']);
        if ($byAdmin) {
            $line = '事務局にて以下のお申し込みをキャンセルしました。'
                  . "\nご不明な点は事務局までお問い合わせください。";
        } else {
            $line = $was === BookingStatus::Waitlisted
                ? 'キャンセル待ちのお申し込みを取り消しました。'
                : 'ご予約をキャンセルしました。';
        }

        $body = <<<TEXT
        {$found['name']} 様

        {$line}

        ── キャンセルした内容 ──────────
        イベント　: {$found['event_title']}
        主催　　　: {$found['company_name']}
        日時　　　: {$when}
        人数　　　: {$found['party_size']} 名
        予約番号　: {$found['reference_code']}
        ────────────────────

        またのお申し込みをお待ちしています。
        このメールに心当たりがない場合は、お手数ですが破棄してください。
        TEXT;

        $this->mailQueue->enqueue(
            (string) $found['email'],
            (string) $found['name'],
            "【イベント予約】キャンセルを受け付けました：{$found['event_title']}",
            $body,
            (int) $found['id']
        );
    }

    /**
     * Seats just came free on a session where people are waiting. Promotion is
     * a human decision (waitlist.auto_promote defaults to false: the head of
     * the queue may need more seats than were released), so tell the admin
     * rather than acting.
     */
    private function notifyAdminOfVacancy(int $sessionId, array $found, int $freedSeats): void
    {
        $adminTo = Config::string('mail.admin_to');
        if ($adminTo === '') {
            return;
        }

        $waiting = (int) Db::scalar(
            "SELECT COUNT(*) FROM bookings WHERE session_id = ? AND status = 'waitlisted'",
            [$sessionId]
        );
        if ($waiting === 0) {
            return;
        }

        $when = jp_datetime((string) $found['starts_at']) . '〜' . jp_time((string) $found['ends_at']);

        $body = <<<TEXT
        キャンセルにより空きが出ました。キャンセル待ちの繰り上げをご検討ください。

        イベント　　　　: {$found['event_title']}（{$found['company_name']}）
        日時　　　　　　: {$when}
        開催回ID　　　　: {$sessionId}
        解放された席数　: {$freedSeats} 名分
        キャンセル待ち　: {$waiting} 件

        繰り上げは管理画面から行えます（自動繰り上げは無効です）。
        TEXT;

        $this->mailQueue->enqueue(
            $adminTo,
            null,
            "【イベント予約】空きが出ました（キャンセル待ち {$waiting} 件）：{$found['event_title']}",
            $body,
            (int) $found['id']
        );
    }
}
