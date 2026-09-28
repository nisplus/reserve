<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How another booking of the same applicant sits against the one being looked
 * at, on the same day.
 *
 * Three kinds rather than a boolean, because they call for different answers.
 * A visitor touring several companies in one day is the normal use of this
 * site, so "they have another booking" is information, not a problem; only the
 * two that make the day impossible - or nearly so - are worth colouring.
 */
enum ScheduleClash: string
{
    /** The two sessions run at the same time. Nobody can attend both. */
    case Overlap = 'overlap';

    /**
     * No overlap, but the gap is at or under the travel buffer and the two are
     * hosted by different companies - the "can they physically get there"
     * case. Same-company back-to-back sessions are not this: there is nowhere
     * to travel to.
     */
    case Tight = 'tight';

    /** Another booking the same day that leaves enough time. Just context. */
    case SameDay = 'same_day';

    public function label(): string
    {
        return match ($this) {
            self::Overlap => '時間が重複',
            self::Tight   => '移動時間が短い',
            self::SameDay => '同日',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Overlap => 'badge--bad',
            self::Tight   => 'badge--warn',
            self::SameDay => 'badge--muted',
        };
    }

    /** Whether the operator should look before acting. */
    public function needsAttention(): bool
    {
        return $this !== self::SameDay;
    }
}
