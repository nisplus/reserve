<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One-shot messages that survive a redirect.
 */
final class Flash
{
    private const KEY = '_flash';

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    private static function add(string $type, string $message): void
    {
        SessionManager::start();
        $messages = SessionManager::get(self::KEY, []);
        if (!is_array($messages)) {
            $messages = [];
        }
        $messages[] = ['type' => $type, 'message' => $message];
        SessionManager::set(self::KEY, $messages);
    }

    /** @return array<int, array{type:string, message:string}> */
    public static function take(): array
    {
        SessionManager::start();
        $messages = SessionManager::get(self::KEY, []);
        SessionManager::forget(self::KEY);
        return is_array($messages) ? $messages : [];
    }
}
