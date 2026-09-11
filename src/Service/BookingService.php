<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use App\Core\Db;
use App\Domain\AgeRange;
use App\Domain\BookingStatus;
use App\Domain\SessionStatus;
use App\Exception\DuplicateBookingException;
use App\Exception\NotFoundException;
use App\Exception\SessionFullException;
use App\Exception\TravelBufferException;
use App\Exception\ValidationException;
use App\Repository\ApplicantRepository;
use App\Repository\BookingAttendeeRepository;
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
    /**
     * Ceiling on bookings.guardian_count. Not a database CHECK: the constraint
     * added in migration 002 was refused by MariaDB 11.8 (errno 1901) even as
     * a standalone ALTER, so this class holds the rule and migration 007 says
     * so. TINYINT UNSIGNED still keeps it non-negative.
     */
    public const GUARDIAN_MAX = 20;

    /**
     * Would this application have to wait rather than being confirmed?
     *
     * Two reasons, and the second is the less obvious one: a seat freed by a
     * cancellation belongs to the queue, not to whoever happens to apply next.
     * Without that rule the seat goes back on sale the moment it is released,
     * and someone applying afterwards is confirmed ahead of people who have
     * been waiting - which is the opposite of what a waitlist is for. So while
     * anyone is waiting on a session, further applications join the queue
     * behind them however many seats are free.
     *
     * The cost is that a gap nobody in the queue can use (one seat free, only
     * a party of three waiting) stays empty until the office acts on it. That
     * is deliberate: it is visible on the dashboard, and the alternative
     * trades away the guarantee for the people already in line.
     *
     * Static and shared so the screens cannot answer this differently from the
     * transaction that decides it.
     */
    public static function wouldWaitlist(int $seatsLeft, int $partySize, int $waitingCount): bool
    {
        return $waitingCount > 0 || $partySize > $seatsLeft;
    }

    public function __construct(
        private readonly ApplicantRepository $applicants = new ApplicantRepository(),
        private readonly BookingRepository $bookings = new BookingRepository(),
        private readonly MailQueueRepository $mailQueue = new MailQueueRepository(),
        private readonly BookingAttendeeRepository $attendees = new BookingAttendeeRepository(),
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
     *
     * @param array<int, string> $companionNames Names of the people brought
     *        along, in order, for attendee_no 2..N. Optional: the web form
     *        demands them, but CLI callers and the concurrency harness must
     *        not have to invent people, so a short list simply records fewer
     *        names. The applicant is always recorded as attendee_no 1.
     * @param array<int, int|null> $ages Ages for attendee_no 1..N, in the same
     *        order (index 0 is the applicant). Optional, same reasoning.
     * @param string|null $phone A number to reach the party on the day.
     * @param string|null $message Free text for the host company; optional.
     * @param string|null $contactName Who to contact - the owner of $email and
     *        $phone, which is not always a participant: for a children's
     *        workshop it is the parent. Null falls back to $name, which is
     *        right for an adult booking for themselves and keeps CLI callers
     *        from having to invent a second person.
     * @param int $guardianCount People coming along who are not taking part
     *        (a parent watching, typically). Recorded but NOT charged against
     *        the capacity, and only meaningful for an event whose 参加人数
     *        excludes them - forced to 0 for the events that count them, where
     *        they are already inside $partySize.
     */
    public function book(
        int $sessionId,
        string $email,
        string $name,
        int $partySize,
        bool $allowWaitlist = true,
        array $companionNames = [],
        array $ages = [],
        ?string $phone = null,
        ?string $message = null,
        int $guardianCount = 0,
        ?string $contactName = null,
    ): array {
        // Step 0, outside the transaction: make sure the applicant row exists.
        // Doing this first keeps the locked section from having to create it,
        // and an orphan applicant row costs nothing if what follows fails.
        $applicantId = $this->applicants->idForEmail($email);

        try {
            return Db::transaction(function () use (
                $sessionId, $email, $name, $partySize, $allowWaitlist, $applicantId,
                $companionNames, $ages, $phone, $message, $guardianCount, $contactName
            ): array {
                // 1) Applicant gate. From here to commit, this person's
                //    bookings cannot change under us.
                if (!$this->applicants->lock($applicantId)) {
                    throw new NotFoundException('予約者情報を取得できませんでした。もう一度お試しください。');
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

                // The 予約不要 flag lives on the parent event, so it needs its
                // own read - kept out of the locking SELECT above, which must
                // stay a single-row lock on event_sessions. The controller
                // already refuses these; this closes the CLI and service paths
                // and any screen added later.
                $event = Db::selectOne(
                    'SELECT company_id, booking_required, max_party_size, party_includes_guardians,
                            min_age, max_age
                     FROM events WHERE id = ?',
                    [(int) $session['event_id']]
                ) ?? [];
                if ((int) ($event['booking_required'] ?? 0) !== 1) {
                    throw new ValidationException('この体験プログラムは予約不要です。');
                }

                // Where 参加人数 already includes the people coming along, a
                // separate count of them would be the same people twice. The
                // form does not offer the field for these events; this is for
                // a hand-made POST and for CLI callers, and it drops the value
                // rather than erroring - there is nothing for the applicant to
                // fix, and the number simply has no meaning here.
                if ((int) ($event['party_includes_guardians'] ?? 0) === 1) {
                    $guardianCount = 0;
                }
                if ($guardianCount < 0 || $guardianCount > self::GUARDIAN_MAX) {
                    throw new ValidationException(
                        '付き添いの人数は 0〜' . self::GUARDIAN_MAX . ' 名でご入力ください。'
                    );
                }

                // The per-application cap. Checked here as well as in the
                // form so a hand-made POST cannot exceed it, and re-read
                // under the transaction so a cap lowered a moment ago wins.
                $maxParty = (int) ($event['max_party_size'] ?? 0);
                if ($maxParty > 0 && $partySize > $maxParty) {
                    throw new ValidationException(
                        "この体験プログラムは1回のご予約につき {$maxParty} 名までです。"
                    );
                }

                /*
                 * Age limits, on every age the caller supplied. Before the
                 * seat decision on purpose: an application outside the range
                 * must not become a waitlist entry either, or the queue fills
                 * with people who could never be promoted.
                 *
                 * Only the ages given are checked. The web form requires one
                 * per participant, so that is all of them; a CLI caller may
                 * pass none, and inventing an age to reject it on would be
                 * worse than letting the caller be responsible.
                 */
                $ageRange = AgeRange::fromEvent($event);
                if (!$ageRange->isUnbounded()) {
                    foreach ($ages as $index => $age) {
                        if ($age === null) {
                            continue;
                        }
                        if (!$ageRange->accepts((int) $age)) {
                            throw new ValidationException(sprintf(
                                '%d人目の年齢は対象年齢の範囲外です（%s）。',
                                $index + 1,
                                $ageRange->label()
                            ));
                        }
                    }
                }
                // tryFrom, not from: an ENUM value this build of the code does
                // not know about (a migration deployed ahead of the code, say)
                // would otherwise raise \ValueError and surface as a 500. A
                // status we cannot interpret is treated as "not open", so the
                // unknown case fails closed rather than selling seats.
                if (SessionStatus::tryFrom((string) $session['status']) !== SessionStatus::Open) {
                    throw new ValidationException('この開催回は現在ご予約を受け付けていません。');
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

                // 3a) One booking per person per event. Two sessions of the
                //     same event never overlap each other, so the check above
                //     would let someone take the 10:00 tour and the 14:00 one.
                //     Same lock, so the answer cannot go stale.
                $sameEvent = $this->bookings->findSameEvent($applicantId, (int) $session['event_id']);
                if ($sameEvent !== null) {
                    throw DuplicateBookingException::sameEvent($sameEvent);
                }

                // 3b) Travel buffer, same lock so the answer cannot go stale.
                //     Only the blocking mode enforces here; in warn mode the
                //     confirmation screen has already shown the popup and the
                //     applicant chose to continue.
                if (self::travelBufferBlocks()) {
                    $near = $this->bookings->findWithinTravelBuffer(
                        $applicantId,
                        (string) $session['starts_at'],
                        (string) $session['ends_at'],
                        self::travelBufferMinutes(),
                        exemptCompanyId: (int) ($event['company_id'] ?? 0) ?: null
                    );
                    if ($near !== null) {
                        throw TravelBufferException::tooClose(
                            $near,
                            self::gapMinutes($near, (string) $session['starts_at'], (string) $session['ends_at']),
                            self::travelBufferMinutes()
                        );
                    }
                }

                // 4) Seats or waitlist. Plain read-modify-write is safe here:
                //    the session row is locked, so the counters cannot move.
                $seatsLeft = (int) $session['capacity'] - (int) $session['confirmed_seats'];

                // Whether anyone is already in the queue. Counted under the
                // session lock, which is the same lock every path that adds to
                // or removes from the queue must hold - so this cannot go stale
                // between here and the insert below.
                $waitingCount = (int) Db::scalar(
                    "SELECT COUNT(*) FROM bookings
                     WHERE session_id = ? AND status = 'waitlisted'",
                    [$sessionId]
                );

                if (!self::wouldWaitlist($seatsLeft, $partySize, $waitingCount)) {
                    $status = BookingStatus::Confirmed;
                    $waitlistSeq = null;
                    Db::execute(
                        'UPDATE event_sessions SET confirmed_seats = confirmed_seats + ? WHERE id = ?',
                        [$partySize, $sessionId]
                    );
                } else {
                    if (!$allowWaitlist) {
                        // 0, not the seats that happen to be free: to a new
                        // application none of them are available while the
                        // queue holds them, and reporting 残り 2 名 on a
                        // refusal would be the same lie the fix is about.
                        throw new SessionFullException(
                            $waitingCount > 0 ? 0 : max($seatsLeft, 0)
                        );
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

                $bookingId = $this->bookings->insert(
                    referenceCode:   $referenceCode,
                    sessionId:       $sessionId,
                    applicantId:     $applicantId,
                    email:           $email,
                    name:            $name,
                    partySize:       $partySize,
                    status:          $status,
                    waitlistSeq:     $waitlistSeq,
                    cancelTokenHash: $token['hash'],
                    phone:           $phone,
                    message:         $message,
                    guardianCount:   $guardianCount,
                    contactName:     $contactName,
                );

                // 6) Who is coming. attendee_no 1 is the applicant; the rest
                //    follow in the order they were entered. Inside the
                //    transaction, so a rollback takes them with it.
                $this->attendees->replaceFor(
                    $bookingId,
                    array_merge([$name], array_slice($companionNames, 0, max(0, $partySize - 1))),
                    array_slice($ages, 0, $partySize)
                );

                // 7) Audit trail.
                $this->bookings->logEvent($bookingId, null, $status->value, 'applicant');

                // 8) Outbox. Queued in-transaction: a rollback takes the mail
                //    with it. The raw token exists only inside this body.
                $this->enqueueConfirmationMail(
                    $email,
                    // Addressed to the contact, not the participant: it is
                    // their address, and a confirmation opening 太郎 様 when
                    // 太郎 is eight years old is not who is reading it.
                    $contactName ?? $name,
                    $status,
                    $waitlistSeq,
                    $referenceCode,
                    $partySize,
                    $sessionId,
                    (string) $session['starts_at'],
                    (string) $session['ends_at'],
                    $token['raw'],
                    $bookingId,
                    $this->attendees->namesFor($bookingId),
                    $message,
                    $guardianCount
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
            // apply that slipped past the overlap check: expected, and the
            // applicant's to fix. The identification goes through errno 1062
            // plus the index name parsed out of errorInfo, so it does not
            // depend on the wording of the server's error message.
            //
            // The table's other unique keys are cancel_token_hash (256 bits)
            // and reference_code (48 bits), both freshly generated per attempt.
            // A collision there is a chance event rather than a user mistake,
            // so it deliberately stays an error instead of being reported as a
            // duplicate application; at these sizes it is not expected to occur.
            if (Db::isDuplicateKeyFor($e, 'uq_bookings_active')) {
                throw DuplicateBookingException::sameSession();
            }
            throw $e;
        }
    }

    // --- travel buffer ------------------------------------------------------

    /** Configured gap (minutes) under which two bookings are "too close". 0 disables. */
    public static function travelBufferMinutes(): int
    {
        return max(0, Config::int('travel_buffer.minutes', 15));
    }

    /** true: refuse such bookings outright; false: warn on the confirm screen only. */
    public static function travelBufferBlocks(): bool
    {
        return self::travelBufferMinutes() > 0 && Config::bool('travel_buffer.block', false);
    }

    /**
     * Advisory version for the confirmation screen: would this booking sit
     * within the travel buffer of one this e-mail address already holds?
     *
     * Lock-free on purpose - it renders a warning, it does not decide. The
     * deciding check (block mode) runs inside book() under the applicant
     * lock. No applicant row yet means no bookings and no warning; the lookup
     * deliberately does not create one.
     *
     * @param array<string, mixed> $session Row with starts_at / ends_at.
     * @return array{conflict: array<string, mixed>, gap_minutes: int}|null
     */
    public function travelBufferWarning(string $email, array $session): ?array
    {
        $buffer = self::travelBufferMinutes();
        if ($buffer <= 0) {
            return null;
        }

        $applicantId = $this->applicants->findIdByEmail($email);
        if ($applicantId === null) {
            return null;
        }

        $near = $this->bookings->findWithinTravelBuffer(
            $applicantId,
            (string) $session['starts_at'],
            (string) $session['ends_at'],
            $buffer,
            exemptCompanyId: (int) ($session['company_id'] ?? 0) ?: null
        );
        if ($near === null) {
            return null;
        }

        return [
            'conflict'    => $near,
            'gap_minutes' => self::gapMinutes($near, (string) $session['starts_at'], (string) $session['ends_at']),
        ];
    }

    /** Minutes between the two bookings' facing edges; 0 for back-to-back. */
    private static function gapMinutes(array $near, string $startsAt, string $endsAt): int
    {
        $gap = strtotime((string) $near['starts_at']) >= strtotime($endsAt)
            ? strtotime((string) $near['starts_at']) - strtotime($endsAt)   // they follow us
            : strtotime($startsAt) - strtotime((string) $near['ends_at']);  // they precede us
        return max(0, intdiv($gap, 60));
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
        array $attendeeNames = [],
        ?string $message = null,
        int $guardianCount = 0,
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
            $subject = "【はいてくヒルズ予約】ご予約が確定しました：{$context['event_title']}";
            $headline = 'ご予約を受け付け、参加が確定しました。';
        } else {
            $subject = "【はいてくヒルズ予約】キャンセル待ちで受け付けました：{$context['event_title']}";
            $headline = "満席のため、キャンセル待ち（受付順 {$waitlistSeq} 番）で受け付けました。\n"
                      . 'お席をご用意できるようになりましたら、改めてご連絡します。';
        }

        $venueLine = ($context['venue'] ?? null) !== null && $context['venue'] !== ''
            ? "会場　　　: {$context['venue']}\n"
            : '';

        /*
         * Listed when it says something the addressee line does not. That used
         * to mean "more than one person", on the assumption that a party of one
         * was the person being written to - which stopped being true once the
         * contact could be someone else. A parent booking for one child would
         * otherwise get a mail naming only the parent.
         *
         * The list may be shorter than party_size when names were not collected.
         */
        $attendeeLines = '';
        if (count($attendeeNames) > 1
            || (count($attendeeNames) === 1 && $attendeeNames[0] !== $name)
        ) {
            $attendeeLines = "ご参加者　:\n";
            foreach ($attendeeNames as $index => $attendee) {
                $attendeeLines .= sprintf("            %d. %s\n", $index + 1, $attendee);
            }
        }

        // Only the events that keep them separate ever have a count here, so
        // the line simply does not appear for the ones that do not.
        $guardianLine = $guardianCount > 0
            ? "付き添い　: {$guardianCount} 名（体験されない方）\n"
            : '';

        // Echo the message back so the applicant has a record of what they
        // sent, and can see it arrived rather than wondering.
        $messageLines = '';
        if ($message !== null && trim($message) !== '') {
            $messageLines = "\n── 開催企業へのメッセージ ──────\n" . trim($message)
                . "\n────────────────────\n";
        }

        $body = <<<TEXT
        {$name} 様

        {$headline}

        ── 予約内容 ──────────────
        体験内容　: {$context['event_title']}
        開催企業　: {$context['company_name']}
        日時　　　: {$when}
        {$venueLine}人数　　　: {$partySize} 名
        {$guardianLine}{$attendeeLines}予約番号　: {$referenceCode}
        ────────────────────
        {$messageLines}

        ▼ 予約内容の確認・キャンセルはこちら
        {$manageUrl}

        このURLはご予約されたご本人だけのものです。他の方に知られないようご注意ください。
        心当たりのないメールの場合は、そのまま破棄してください。
        TEXT;

        $this->mailQueue->enqueue($email, $name, $subject, $body, $bookingId);
    }
}
