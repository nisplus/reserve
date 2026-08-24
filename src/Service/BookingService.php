<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Db;
use App\Domain\BookingStatus;
use App\Domain\SessionStatus;
use App\Exception\DuplicateBookingException;
use App\Exception\NotFoundException;
use App\Exception\SessionFullException;
use App\Exception\ValidationException;
use App\Repository\ApplicantRepository;
use App\Repository\BookingRepository;
use App\Repository\MailQueueRepository;
use PDOException;

/**
 * The booking transaction. This is the one place the system's correctness
 * lives; docs/design.md section B is the specification for this file.
 *
 * Invariants protected here:
 *   - an applicant never holds two live bookings with overlapping times
 *     (across companies; waitlisted counts as holding the time)
 *   - confirmed_seats never exceeds capacity
 *   - waitlist_seq is unique per session
 *
 * How: two locks taken in the fixed order applicants -> event_sessions
 * (-> bookings), under READ COMMITTED.
 *
 *   - The applicant row serialises everything one person does, which is what
 *     makes the overlap check safe: overlap is a range comparison, and rows
 *     that do not exist yet cannot be locked, so a parent row stands in.
 *   - The session row serialises seat accounting. The denormalised
 *     confirmed_seats counter is only ever read and written under this lock,
 *     which is why it can be trusted where SUM(party_size) could not be.
 *
 * Every operation that touches these tables (cancel in stage 5, promote in
 * stage 8) must take the same locks in the same order, or deadlocks return.
 */
final class BookingService
{
    public function __construct(
        private readonly ApplicantRepository $applicants = new ApplicantRepository(),
        private readonly BookingRepository $bookings = new BookingRepository(),
        private readonly MailQueueRepository $mailQueue = new MailQueueRepository(),
    ) {
    }

    /**
     * Book $partySize seats on a session, falling back to the waitlist when
     * full unless $allowWaitlist is false.
     *
     * $email must already be normalised (Validator::normalizeEmail) and
     * validated; this method treats its inputs as clean.
     *
     * @return array{
     *   booking_id: int, reference_code: string, status: BookingStatus,
     *   waitlist_seq: int|null,
     * }
     * @throws DuplicateBookingException time overlap, or same session twice
     * @throws SessionFullException      full and the caller declined the waitlist
     * @throws NotFoundException         no such session
     * @throws ValidationException       session closed for applications
     */
    public function book(
        int $sessionId,
        string $email,
        string $name,
        int $partySize,
        bool $allowWaitlist = true,
    ): array {
        // Step 0, outside the transaction: make sure the applicant row exists.
        // Doing this first keeps the locked section from having to create it,
        // and an orphan applicant row costs nothing if what follows fails.
        $applicantId = $this->applicants->idForEmail($email);

        try {
            return Db::transaction(function () use (
                $sessionId, $email, $name, $partySize, $allowWaitlist, $applicantId
            ): array {
                // 1) Applicant gate. From here to commit, this person's
                //    bookings cannot change under us.
                if (!$this->applicants->lock($applicantId)) {
                    throw new NotFoundException('申込者情報を取得できませんでした。もう一度お試しください。');
                }

                // 2) Session row lock: seat accounting is serialised on this
                //    row. Times and status are re-read under the lock rather
                //    than trusted from the screen the user was looking at.
                $session = Db::selectOne(
                    'SELECT event_id, starts_at, ends_at, capacity, confirmed_seats,
                            waitlist_counter, status
                     FROM event_sessions WHERE id = ? FOR UPDATE',
                    [$sessionId]
                );
                if ($session === null) {
                    throw new NotFoundException('お探しの開催回は見つかりませんでした。');
                }
                if (SessionStatus::from((string) $session['status']) !== SessionStatus::Open) {
                    throw new ValidationException('この開催回は現在お申し込みを受け付けていません。');
                }

                // 3) Overlap check, inside the applicant lock so nothing can
                //    commit between check and insert. The target session's own
                //    range overlaps itself, so booking the same session twice
                //    is caught here too - it just deserves a clearer message.
                $conflict = $this->bookings->findOverlapping(
                    $applicantId,
                    (string) $session['starts_at'],
                    (string) $session['ends_at']
                );
                if ($conflict !== null) {
                    throw (int) $conflict['session_id'] === $sessionId
                        ? DuplicateBookingException::sameSession()
                        : DuplicateBookingException::overlapping($conflict);
                }

                // 4) Seats or waitlist. Plain read-modify-write is safe here:
                //    the session row is locked, so the counters cannot move.
                $seatsLeft = (int) $session['capacity'] - (int) $session['confirmed_seats'];

                if ($partySize <= $seatsLeft) {
                    $status = BookingStatus::Confirmed;
                    $waitlistSeq = null;
                    Db::execute(
                        'UPDATE event_sessions SET confirmed_seats = confirmed_seats + ? WHERE id = ?',
                        [$partySize, $sessionId]
                    );
                } else {
                    if (!$allowWaitlist) {
                        throw new SessionFullException(max($seatsLeft, 0));
                    }
                    $status = BookingStatus::Waitlisted;
                    $waitlistSeq = (int) $session['waitlist_counter'] + 1;
                    Db::execute(
                        'UPDATE event_sessions SET waitlist_counter = ? WHERE id = ?',
                        [$waitlistSeq, $sessionId]
                    );
                }

                // 5) The booking row. Fresh randomness on every attempt so a
                //    transaction replay (deadlock retry) or a freak reference
                //    collision never re-uses identifiers.
                $token = TokenService::newCancelToken();
                $referenceCode = TokenService::newReferenceCode();

                $bookingId = $this->bookings->insert([
                    'reference_code'    => $referenceCode,
                    'session_id'        => $sessionId,
                    'applicant_id'      => $applicantId,
                    'email'             => $email,
                    'name'              => $name,
                    'party_size'        => $partySize,
                    'status'            => $status->value,
                    'waitlist_seq'      => $waitlistSeq,
                    'cancel_token_hash' => $token['hash'],
                    'confirmed'         => $status === BookingStatus::Confirmed,
                ]);

                // 6) Audit trail.
                $this->bookings->logEvent($bookingId, null, $status->value, 'applicant');

                // 7) Outbox. Queued in-transaction: a rollback takes the mail
                //    with it. The raw token exists only inside this body.
                $this->enqueueConfirmationMail(
                    $email,
                    $name,
                    $status,
                    $waitlistSeq,
                    $referenceCode,
                    $partySize,
                    $sessionId,
                    (string) $session['starts_at'],
                    (string) $session['ends_at'],
                    $token['raw'],
                    $bookingId
                );

                return [
                    'booking_id'     => $bookingId,
                    'reference_code' => $referenceCode,
                    'status'         => $status,
                    'waitlist_seq'   => $waitlistSeq,
                ];
            });
        } catch (PDOException $e) {
            // uq_bookings_active is the backstop for a same-session double
            // apply that slipped past the overlap check. Translate it; any
            // other constraint violation is a real bug and stays loud.
            if (Db::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_bookings_active')) {
                throw DuplicateBookingException::sameSession();
            }
            throw $e;
        }
    }

    private function enqueueConfirmationMail(
        string $email,
        string $name,
        BookingStatus $status,
        ?int $waitlistSeq,
        string $referenceCode,
        int $partySize,
        int $sessionId,
        string $startsAt,
        string $endsAt,
        string $rawToken,
        int $bookingId,
    ): void {
        // Display names only; no lock needed and no harm if they change later.
        $context = Db::selectOne(
            'SELECT e.title AS event_title, e.venue, c.name AS company_name
             FROM event_sessions s
             JOIN events e    ON e.id = s.event_id
             JOIN companies c ON c.id = e.company_id
             WHERE s.id = ?',
            [$sessionId]
        ) ?? ['event_title' => '', 'venue' => null, 'company_name' => ''];

        $when = jp_datetime($startsAt) . '〜' . jp_time($endsAt);
        $manageUrl = Config::url('/manage/' . $rawToken);

        if ($status === BookingStatus::Confirmed) {
            $subject = "【イベント予約】お申し込みが確定しました：{$context['event_title']}";
            $headline = 'お申し込みを受け付け、参加が確定しました。';
        } else {
            $subject = "【イベント予約】キャンセル待ちで受け付けました：{$context['event_title']}";
            $headline = "満席のため、キャンセル待ち（受付順 {$waitlistSeq} 番）で受け付けました。\n"
                      . 'お席をご用意できるようになりましたら、改めてご連絡します。';
        }

        $venueLine = ($context['venue'] ?? null) !== null && $context['venue'] !== ''
            ? "会場　　　: {$context['venue']}\n"
            : '';

        $body = <<<TEXT
        {$name} 様

        {$headline}

        ── お申し込み内容 ──────────────
        イベント　: {$context['event_title']}
        主催　　　: {$context['company_name']}
        日時　　　: {$when}
        {$venueLine}人数　　　: {$partySize} 名
        予約番号　: {$referenceCode}
        ────────────────────

        ▼ お申し込み内容の確認・キャンセルはこちら
        {$manageUrl}

        このURLはお申し込みされたご本人だけのものです。他の方に知られないようご注意ください。
        心当たりのないメールの場合は、そのまま破棄してください。
        TEXT;

        $this->mailQueue->enqueue($email, $name, $subject, $body, $bookingId);
    }
}
