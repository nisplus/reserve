<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A half-open interval [start, end).
 *
 * Half-open is what makes back-to-back slots work: 10:00-11:00 and 11:00-12:00
 * do not overlap, so an applicant can book both. The SQL overlap predicate uses
 * strict `<` on both sides for the same reason.
 */
final class TimeRange
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('終了日時は開始日時より後である必要があります。');
        }
    }

    public static function fromStrings(string $start, string $end): self
    {
        return new self(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function startsAt(): string
    {
        return $this->start->format('Y-m-d H:i:s');
    }

    public function endsAt(): string
    {
        return $this->end->format('Y-m-d H:i:s');
    }

    public function minutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }

    /** "2026-09-01 (火) 10:00〜10:45" */
    public function describe(): string
    {
        return jp_datetime($this->startsAt()) . '〜' . jp_time($this->endsAt());
    }
}
