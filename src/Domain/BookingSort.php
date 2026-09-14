<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The orders the admin booking list can be shown in.
 *
 * An enum rather than a string the caller passes through, because the value
 * ends up in an ORDER BY clause, which is the one part of the query that
 * cannot be a bound parameter. Going through `tryFrom` means an unrecognised
 * value becomes the default rather than SQL.
 */
enum BookingSort: string
{
    /** Newest application first. What the list has always done. */
    case Newest = 'newest';

    /**
     * The order the day is actually run in: walk the companies, then each
     * company's programmes in the order they are displayed, then each
     * programme's sessions in time order, and within one session put the
     * people who are coming before the people who might be.
     */
    case Schedule = 'schedule';

    public static function fromRequest(string $value): self
    {
        return self::tryFrom($value) ?? self::Newest;
    }

    public function label(): string
    {
        return match ($this) {
            self::Newest   => '予約日時（新しい順）',
            self::Schedule => '会社・体験プログラム・開催日時順',
        };
    }

    /**
     * The ORDER BY body. Interpolated into the query, so every branch here is
     * a literal written in this file - never anything derived from input.
     *
     * b.id last in both: two bookings can share a created_at, and two people
     * in one session can share a status, so without it the page boundaries of
     * a paginated list are not stable and a row can appear on two pages or
     * neither.
     */
    public function orderBy(): string
    {
        return match ($this) {
            self::Newest => 'b.created_at DESC, b.id DESC',
            /*
             * The status ordering is spelled out rather than left to the
             * column. bookings.status is an ENUM declared
             * ('confirmed','waitlisted','cancelled') and MariaDB sorts an ENUM
             * by that declaration order, which happens to be exactly the order
             * wanted here - so `ORDER BY b.status` would work today and would
             * silently reorder the list the day someone adds a value to the
             * ENUM or rewrites the column. CASE says what is meant.
             */
            self::Schedule => 'c.id, e.sort_order, e.id, s.starts_at, '
                . "CASE b.status
                       WHEN 'confirmed'  THEN 1
                       WHEN 'waitlisted' THEN 2
                       WHEN 'cancelled'  THEN 3
                       ELSE 4
                   END, "
                . 'b.waitlist_seq, b.id',
        };
    }

    /** @return array<string, string> value => label, for the select box. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }
        return $out;
    }
}
