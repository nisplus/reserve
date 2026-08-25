<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * The new booking does not overlap an existing one, but sits closer to it
 * than the configured travel buffer allows. Raised only when
 * travel_buffer.block is on; in warn mode the proximity is shown on the
 * confirmation screen instead and the booking goes through.
 *
 * Carries the neighbouring booking so the message can name it - "too close
 * to X at 10:45" is actionable where a bare refusal is not.
 */
class TravelBufferException extends RuntimeException
{
    /** @param array<string, mixed> $conflict */
    public function __construct(
        string $message,
        public readonly array $conflict = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $conflict */
    public static function tooClose(array $conflict, int $gapMinutes, int $bufferMinutes): self
    {
        $when = isset($conflict['starts_at'], $conflict['ends_at'])
            ? sprintf('%s〜%s', jp_datetime((string) $conflict['starts_at']), jp_time((string) $conflict['ends_at']))
            : '';

        return new self(sprintf(
            '移動時間を考慮すると、この予約は間に合いません。'
            . '%s「%s」%s との間隔が %d 分しかありません（%d 分以下は受け付けない設定です）。',
            (string) ($conflict['company_name'] ?? ''),
            (string) ($conflict['event_title'] ?? ''),
            $when,
            $gapMinutes,
            $bufferMinutes
        ), $conflict);
    }
}
