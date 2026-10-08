<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How full a booth or a session is, as a person standing there would say it.
 *
 * Four steps rather than a number, because on the day the number is not known:
 * walk-up tickets are paper, so nobody can count what is left from the
 * database. What a company CAN tell us over chat is which of these four it
 * feels like, and that is enough for a visitor deciding where to walk.
 *
 * A ticket count can be attached to a report as well, but it is the junior
 * partner - it can be wrong a minute after it is entered, while ◎ and ✕ stay
 * true for longer.
 */
enum VacancyLevel: string
{
    /** ◎ Walk up and join. */
    case Open = 'open';

    /** ◯ Seats to spare. */
    case Ample = 'ample';

    /** △ Nearly full. */
    case Few = 'few';

    /** ✕ Nothing left. */
    case None = 'none';

    /** The mark, which carries the meaning on its own - no colour needed. */
    public function mark(): string
    {
        return match ($this) {
            self::Open  => '◎',
            self::Ample => '◯',
            self::Few   => '△',
            self::None  => '✕',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open  => '今すぐ参加できます',
            self::Ample => '残席にゆとりがあります',
            self::Few   => '残数わずか',
            self::None  => '残数なし',
        };
    }

    /** Short form, for places with one line and no room: the admin list. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Open  => '今すぐ',
            self::Ample => 'ゆとり',
            self::Few   => 'わずか',
            self::None  => 'なし',
        };
    }

    /**
     * Sort position on the board: ◎ ◯ △ ✕.
     *
     * Not the order the catalogue uses. Somebody standing in front of the
     * screen is asking "where can I go now", so the places they can go come
     * first and the full ones go last - they answer a question nobody asked.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Open  => 0,
            self::Ample => 1,
            self::Few   => 2,
            self::None  => 3,
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open  => 'badge--ok',
            self::Ample => 'badge--ok',
            self::Few   => 'badge--warn',
            self::None  => 'badge--bad',
        };
    }

    /** CSS modifier for the signage screen, where each step gets its own colour. */
    public function signageClass(): string
    {
        return 'sg--' . $this->value;
    }

    /**
     * The level a report should carry.
     *
     * A remaining count of zero overrides whatever mark was pressed: the two
     * would otherwise contradict each other on screen, and "0 枚" is the more
     * specific claim. Any other count is left to the operator - 3 tickets can
     * reasonably be △ or ✕ depending on how fast the queue is moving.
     */
    public static function reconcile(self $chosen, ?int $remaining): self
    {
        return $remaining === 0 ? self::None : $chosen;
    }

    /** value => label, for the select boxes. @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->mark() . ' ' . $case->label();
        }
        return $out;
    }
}
