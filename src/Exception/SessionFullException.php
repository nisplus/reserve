<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Not enough seats left. Only thrown when the caller explicitly declined the
 * waitlist; the normal path falls back to waitlisting instead of failing.
 */
class SessionFullException extends RuntimeException
{
    public function __construct(
        public readonly int $remaining = 0,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'この開催回は満席です（残り %d 名分）。キャンセル待ちでのご予約をご検討ください。',
            $remaining
        ));
    }
}
