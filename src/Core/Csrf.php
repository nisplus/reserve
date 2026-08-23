<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\ValidationException;

final class Csrf
{
    public const FIELD = '_token';
    private const SESSION_KEY = '_csrf';

    public static function token(): string
    {
        SessionManager::start();
        $token = SessionManager::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            SessionManager::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    /** Issue a fresh token. Called on login so a pre-auth token cannot be reused. */
    public static function rotate(): string
    {
        SessionManager::start();
        $token = bin2hex(random_bytes(32));
        SessionManager::set(self::SESSION_KEY, $token);
        return $token;
    }

    public static function field(): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', self::FIELD, e(self::token()));
    }

    /** @throws ValidationException */
    public static function verify(Request $request): void
    {
        SessionManager::start();
        $expected = SessionManager::get(self::SESSION_KEY);
        $given    = $request->post(self::FIELD);

        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
            throw new ValidationException(
                'セッションの有効期限が切れたか、不正な送信です。お手数ですが最初からやり直してください。'
            );
        }
    }
}
