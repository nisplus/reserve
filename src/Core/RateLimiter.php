<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sliding-window rate limit backed by one small file per key.
 *
 * File-based on purpose: no extra table, no cache daemon, and the public
 * booking POST is the only consumer. Each file holds the recent hit
 * timestamps for one key; reads and writes happen under an exclusive flock,
 * which both PHP builds on Windows and Linux honour.
 *
 * This is a nuisance brake (a stuck F5 key, a naive script), not DoS
 * protection - that belongs in front of PHP.
 */
final class RateLimiter
{
    /** True when the hit is allowed; false when the key is over budget. */
    public static function allow(string $key, int $windowSeconds, int $max): bool
    {
        $dir = APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return true; // never let the brake take the whole flow down
        }

        // Hash the key: an IP (or a forged header, some day) must not be able
        // to choose the filename it is stored under.
        $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($handle);
            $hits = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($hits)) {
                $hits = [];
            }

            $now = time();
            $hits = array_values(array_filter(
                $hits,
                static fn ($t): bool => is_int($t) && $t > $now - $windowSeconds
            ));

            $allowed = count($hits) < $max;
            if ($allowed) {
                $hits[] = $now;
            }
            // Over-budget hits are not recorded: the window measures actual
            // requests served, so the caller recovers as soon as it goes quiet
            // instead of pushing its own lockout further away by retrying.

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($hits));

            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
