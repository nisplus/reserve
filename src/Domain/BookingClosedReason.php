<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Why bookings are not being taken. Three reasons rather than one boolean,
 * because a visitor who arrived early needs a date and a visitor who arrived
 * late needs to know not to wait.
 */
enum BookingClosedReason: string
{
    /** Before the announced opening time. */
    case NotYetOpen = 'not_yet_open';

    /** Past the announced closing time. */
    case Ended = 'ended';

    /** The switch is off - a deliberate stop, whatever the schedule says. */
    case Suspended = 'suspended';

    /** Short label for the slot list, where there is room for a badge and no more. */
    public function badgeLabel(): string
    {
        return match ($this) {
            self::NotYetOpen => '受付開始前',
            self::Ended      => '受付終了',
            self::Suspended  => '受付停止中',
        };
    }
}
