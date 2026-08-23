<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Mirrors the ENUM on bookings.status.
 *
 *   (new) --seats available--> Confirmed --cancel--> Cancelled (terminal)
 *      \--full-------------> Waitlisted --promote--> Confirmed
 *                                       --cancel---> Cancelled (terminal)
 *
 * Cancelled is terminal. Re-applying creates a new row, which the active_key
 * generated column permits because it goes NULL once cancelled.
 */
enum BookingStatus: string
{
    case Confirmed  = 'confirmed';
    case Waitlisted = 'waitlisted';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed  => '確定',
            self::Waitlisted => 'キャンセル待ち',
            self::Cancelled  => 'キャンセル済み',
        };
    }

    /** CSS modifier for the badge component. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Confirmed  => 'badge--ok',
            self::Waitlisted => 'badge--warn',
            self::Cancelled  => 'badge--muted',
        };
    }

    /** Statuses that hold a claim on the applicant's time. */
    public function isActive(): bool
    {
        return $this !== self::Cancelled;
    }

    /** Statuses that consume seats. */
    public function holdsSeat(): bool
    {
        return $this === self::Confirmed;
    }

    /** @return array<int, string> Values counted as active, for SQL IN clauses. */
    public static function activeValues(): array
    {
        return [self::Confirmed->value, self::Waitlisted->value];
    }
}
