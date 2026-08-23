<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * The applicant already holds a booking whose time range overlaps the one they
 * are trying to make. Carries the conflicting booking so the message can name
 * it - "already booked X at 10:00" is far more useful than "duplicate".
 */
class DuplicateBookingException extends RuntimeException
{
    /** @param array<string, mixed> $conflict */
    public function __construct(
        string $message,
        public readonly array $conflict = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $conflict */
    public static function overlapping(array $conflict): self
    {
        $when = isset($conflict['starts_at'], $conflict['ends_at'])
            ? sprintf('%s〜%s', jp_datetime((string) $conflict['starts_at']), jp_time((string) $conflict['ends_at']))
            : '';

        return new self(sprintf(
            '時間帯が重複する予約が既にあります（%s「%s」%s）。同じ時間帯の複数のイベントには申し込めません。',
            (string) ($conflict['company_name'] ?? ''),
            (string) ($conflict['event_title'] ?? ''),
            $when
        ), $conflict);
    }

    /** The applicant already holds a live booking for this very session. */
    public static function sameSession(): self
    {
        return new self('この開催回には既にお申し込み済みです。予約内容の確認メールをご確認ください。');
    }
}
