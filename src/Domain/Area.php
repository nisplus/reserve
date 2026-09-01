<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Mirrors the ENUM on companies.area - which part of the site a host company
 * is in.
 *
 * The stored values are English so they can travel in a query string that
 * people paste to each other (/?area=east). The Japanese names are labels
 * for display only; changing one here changes it everywhere without a
 * migration, and without invalidating links already shared.
 */
enum Area: string
{
    case East  = 'east';
    case South = 'south';
    case North = 'north';
    case Main  = 'main';

    public function label(): string
    {
        return match ($this) {
            self::East  => '東エリア',
            self::South => '南エリア',
            self::North => '北エリア',
            self::Main  => 'テクノプラザ本館',
        };
    }

    /** value => label, for select boxes and filter links. @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }

    /** Label for a stored value that may be null or unrecognised. */
    public static function labelFor(mixed $value): string
    {
        $area = is_string($value) ? self::tryFrom($value) : null;
        return $area?->label() ?? '未設定';
    }
}
