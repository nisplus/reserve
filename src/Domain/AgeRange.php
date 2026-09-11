<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The ages an event accepts. Either end may be absent, and so may both.
 *
 * It exists so the form, the booking transaction and the wording of the
 * refusal cannot disagree about what "対象年齢" means. The check itself is one
 * comparison; what is worth having in one place is the three-way label
 * (6歳以上 / 12歳以下 / 6〜12歳) and the rule that an absent end is no limit
 * rather than zero.
 *
 * Applied to every age the booking collects, with no exception for escorts.
 * That is not a special case: where 参加人数 excludes them they have no age
 * recorded at all (guardian_count is a count), and where it includes them they
 * are participants. So "every age we hold" already means what the rule says.
 */
final class AgeRange
{
    public function __construct(
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('対象年齢の下限は上限以下である必要があります。');
        }
    }

    /**
     * Build from a row of `events`. Tolerates the columns being absent so a
     * caller with a narrower projection does not get a surprise age limit of
     * zero - a missing column means "not asked for", not "no lower bound".
     *
     * @param array<string, mixed> $event
     */
    public static function fromEvent(array $event): self
    {
        $min = $event['min_age'] ?? null;
        $max = $event['max_age'] ?? null;

        // Not the constructor: a stored pair that somehow has min > max must
        // not make every page throw. Reversed bounds would reject everyone,
        // which is worse than treating the row as unbounded and letting the
        // admin screen be the place the pair is corrected.
        $range = new self(null, null);
        $min = $min !== null ? (int) $min : null;
        $max = $max !== null ? (int) $max : null;
        if ($min !== null && $max !== null && $min > $max) {
            return $range;
        }
        return new self($min, $max);
    }

    public function isUnbounded(): bool
    {
        return $this->min === null && $this->max === null;
    }

    public function accepts(int $age): bool
    {
        if ($this->min !== null && $age < $this->min) {
            return false;
        }
        return !($this->max !== null && $age > $this->max);
    }

    /** '' when unbounded, so callers can test it as a flag and print it as a phrase. */
    public function label(): string
    {
        if ($this->min !== null && $this->max !== null) {
            return $this->min === $this->max
                ? "{$this->min} 歳"
                : "{$this->min} 歳〜{$this->max} 歳";
        }
        if ($this->min !== null) {
            return "{$this->min} 歳以上";
        }
        if ($this->max !== null) {
            return "{$this->max} 歳以下";
        }
        return '';
    }
}
