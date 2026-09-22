<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Whether the site is taking bookings at all.
 *
 * Three settings rather than one, because they answer different questions:
 * the switch is the office's hand on the tap, and the two datetimes are the
 * schedule they would otherwise have to sit up and operate by hand. So the
 * switch wins - turning it off stops bookings even inside the window, which is
 * what makes it usable as an emergency stop.
 *
 *   open  ⟺  enabled
 *            AND (opensAt  is unset OR now >= opensAt)
 *            AND (closesAt is unset OR now <  closesAt)
 *
 * The interval is half-open for the same reason TimeRange is: closing at
 * 17:00 means 16:59:59 is in and 17:00:00 is out, with no second belonging to
 * both. Opening is inclusive - the announced minute is the minute it opens.
 *
 * Everything here is JST. bootstrap.php sets date_default_timezone_set(
 * 'Asia/Tokyo') and the connection pins time_zone = '+09:00', so a
 * DateTimeImmutable built here and a DATETIME read from the database are the
 * same wall clock. The comparison is done in PHP rather than SQL so that the
 * screens and the booking transaction cannot answer it differently.
 *
 * Closed is NOT the same as hidden. The event, its sessions, their times and
 * the venue all stay on the public site while bookings are closed - only the
 * way in is shut. That is the same shape a 予約不要 event already has.
 */
final class BookingWindow
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly ?DateTimeImmutable $opensAt = null,
        public readonly ?DateTimeImmutable $closesAt = null,
        /** Shown when closed by the switch or by closesAt; blank falls back to a generic line. */
        public readonly string $closedMessage = '',
    ) {
    }

    public function isOpenAt(DateTimeImmutable $now): bool
    {
        return $this->reasonAt($now) === null;
    }

    /**
     * Why bookings are shut, or null when they are open. The caller turns this
     * into wording; keeping the decision and the wording apart is what lets
     * the form, the slot list and the transaction agree on the reason.
     */
    public function reasonAt(DateTimeImmutable $now): ?BookingClosedReason
    {
        if (!$this->enabled) {
            return BookingClosedReason::Suspended;
        }
        if ($this->opensAt !== null && $now < $this->opensAt) {
            return BookingClosedReason::NotYetOpen;
        }
        if ($this->closesAt !== null && $now >= $this->closesAt) {
            return BookingClosedReason::Ended;
        }
        return null;
    }

    /**
     * What to tell a visitor. Before opening this is a promise with a date in
     * it, which is worth more than "closed" - so the announced time is used
     * even when a custom message is set, and the custom message trails it.
     */
    public function noticeAt(DateTimeImmutable $now): string
    {
        $reason = $this->reasonAt($now);
        if ($reason === null) {
            return '';
        }

        $custom = trim($this->closedMessage);

        if ($reason === BookingClosedReason::NotYetOpen && $this->opensAt !== null) {
            $line = sprintf(
                'ご予約の受付は %s から開始します。',
                jp_datetime($this->opensAt->format('Y-m-d H:i:s'))
            );
            return $custom !== '' ? $line . "\n" . $custom : $line;
        }

        if ($custom !== '') {
            return $custom;
        }

        return $reason === BookingClosedReason::Ended
            ? 'ご予約の受付は終了しました。'
            : 'ただいまご予約の受付を停止しています。';
    }
}
