<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Read-only access to config/config.php, addressed with dotted keys.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private static bool $loaded = false;

    /** @param array<string, mixed> $values */
    public static function load(array $values): void
    {
        self::$values = $values;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            throw new RuntimeException('Config::load() has not been called.');
        }

        $current = self::$values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    public static function require(string $key): mixed
    {
        $value = self::get($key, null);
        if ($value === null) {
            throw new RuntimeException("Missing configuration key: {$key}");
        }
        return $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    /** @return array<mixed> */
    public static function array(string $key): array
    {
        $value = self::get($key, []);
        return is_array($value) ? $value : [];
    }

    /**
     * What this deployment calls itself, for headings and mail subjects.
     *
     * Defaulted rather than required: an install that has not set it still
     * renders sensibly, which matters because the fallback is what anyone
     * who clones the repository sees first.
     */
    public static function siteName(): string
    {
        $name = self::string('site.name');
        return $name !== '' ? $name : '体験予約';
    }

    /**
     * The header link and the browser tab.
     *
     * Separate from siteName() because the two slots read differently:
     * the catalogue heading is followed by 体験内容一覧, while the header
     * wants to say that this is where you book.
     */
    public static function siteTitle(): string
    {
        $title = self::string('site.title');
        return $title !== '' ? $title : self::siteName();
    }

    /** The 【】 of a mail subject; falls back to the site name. */
    public static function mailPrefix(): string
    {
        $prefix = self::string('site.mail_prefix');
        return $prefix !== '' ? $prefix : self::siteName();
    }

    /** Base URL without a trailing slash, for building absolute links. */
    public static function url(string $path = ''): string
    {
        $base = rtrim(self::string('base_url'), '/');
        if ($path === '') {
            return $base;
        }
        return $base . '/' . ltrim($path, '/');
    }
}
