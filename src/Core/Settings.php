<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\BookingWindow;
use DateTimeImmutable;

/**
 * Application-wide settings, stored one row per key in `settings`.
 *
 * The property that matters most is what happens when a row is ABSENT: every
 * read falls back to the default below, and those defaults describe the
 * system as it behaved before this table existed. That is what lets migration
 * 010 be applied to a live site without changing anything - the switch ships
 * off, not on.
 *
 * Read once per request and cached. The values change by hand, minutes apart
 * at most, and a booking transaction that read a stale row would still be
 * checked against the row the next request reads - so a per-request cache
 * costs nothing in correctness and saves a query on every page.
 */
final class Settings
{
    public const BOOKING_ENABLED  = 'booking.enabled';
    public const BOOKING_OPENS_AT = 'booking.opens_at';
    public const BOOKING_CLOSES_AT = 'booking.closes_at';
    public const BOOKING_CLOSED_MESSAGE = 'booking.closed_message';

    public const REMINDER_ENABLED = 'reminder.enabled';
    public const REMINDER_MODE = 'reminder.mode';
    public const REMINDER_INCLUDE_WAITLISTED = 'reminder.include_waitlisted';
    public const REMINDER_NOTICE = 'reminder.notice';
    public const REMINDER_SKIP_SESSIONS = 'reminder.skip_sessions';

    /** Nothing is sent until someone presses the button. */
    public const REMINDER_MODE_APPROVAL = 'approval';

    /** The nightly run sends by itself. */
    public const REMINDER_MODE_AUTO = 'auto';

    /**
     * Known keys and what they mean when unset. Unknown keys are refused
     * rather than stored: a typo in a setter would otherwise write a row that
     * nothing ever reads, and look like it worked.
     *
     * @var array<string, string|null>
     */
    private const DEFAULTS = [
        self::BOOKING_ENABLED         => '1',   // taking bookings, as before this table
        self::BOOKING_OPENS_AT        => null,  // no announced start
        self::BOOKING_CLOSES_AT       => null,  // no announced end
        self::BOOKING_CLOSED_MESSAGE  => null,

        // Off, and in approval mode, so that deploying the reminder does not
        // start mailing applicants on its own. Turning it on is a decision
        // somebody makes, not something a release does.
        self::REMINDER_ENABLED            => '0',
        self::REMINDER_MODE               => self::REMINDER_MODE_APPROVAL,
        self::REMINDER_INCLUDE_WAITLISTED => '1',
        self::REMINDER_NOTICE             => null,
        self::REMINDER_SKIP_SESSIONS      => null,
    ];

    /** @var array<string, string|null>|null Per-request cache; null until loaded. */
    private static ?array $cache = null;

    /** Forget the cache. For tests, and for a request that has just written. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    public static function get(string $name): ?string
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown setting: {$name}");
        }

        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::select('SELECT name, value FROM settings') as $row) {
                self::$cache[(string) $row['name']] = $row['value'] !== null
                    ? (string) $row['value']
                    : null;
            }
        }

        // array_key_exists, not ??: a row explicitly holding NULL means "unset
        // by the operator", which is the same as the default here, but the
        // distinction matters if a default ever stops being null.
        return array_key_exists($name, self::$cache)
            ? self::$cache[$name]
            : self::DEFAULTS[$name];
    }

    public static function set(string $name, ?string $value): void
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown setting: {$name}");
        }

        Db::execute(
            'INSERT INTO settings (name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $value]
        );
        self::forget();
    }

    /** The booking window as configured. Built here so callers never parse the raw strings. */
    public static function bookingWindow(): BookingWindow
    {
        return new BookingWindow(
            enabled: self::get(self::BOOKING_ENABLED) !== '0',
            opensAt: self::datetime(self::BOOKING_OPENS_AT),
            closesAt: self::datetime(self::BOOKING_CLOSES_AT),
            closedMessage: (string) (self::get(self::BOOKING_CLOSED_MESSAGE) ?? ''),
        );
    }

    public static function reminderEnabled(): bool
    {
        return self::get(self::REMINDER_ENABLED) === '1';
    }

    /** True when the nightly run may send without anyone pressing anything. */
    public static function reminderSendsItself(): bool
    {
        return self::reminderEnabled()
            && self::get(self::REMINDER_MODE) === self::REMINDER_MODE_AUTO;
    }

    public static function reminderIncludesWaitlisted(): bool
    {
        return self::get(self::REMINDER_INCLUDE_WAITLISTED) !== '0';
    }

    /**
     * Sessions the office has taken out of the reminder - in practice, the
     * ones that were cancelled.
     *
     * A list in a settings row rather than a column on event_sessions,
     * because that column would be a schema change to the table the booking
     * transaction locks, for a list that holds a handful of ids a year.
     * Session ids are never reused, so an entry stays correct forever.
     *
     * @return array<int, int>
     */
    public static function reminderSkippedSessions(): array
    {
        $raw = trim((string) (self::get(self::REMINDER_SKIP_SESSIONS) ?? ''));
        if ($raw === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @param array<int, int> $ids */
    public static function setReminderSkippedSessions(array $ids): void
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        sort($clean);
        self::set(self::REMINDER_SKIP_SESSIONS, $clean === [] ? null : implode(',', $clean));
    }

    /**
     * A stored datetime, or null. An unparseable value reads as null rather
     * than throwing: a broken row must not take the public site down, and an
     * absent bound is the harmless direction (the window simply does not
     * close, which the admin screen can then be used to fix).
     */
    private static function datetime(string $name): ?DateTimeImmutable
    {
        $raw = trim((string) (self::get($name) ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
