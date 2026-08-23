<?php

declare(strict_types=1);

namespace App\Domain;

/** Mirrors the ENUM on event_sessions.status. */
enum SessionStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open   => '受付中',
            self::Closed => '受付終了',
        };
    }

    public function acceptsBookings(): bool
    {
        return $this === self::Open;
    }
}
