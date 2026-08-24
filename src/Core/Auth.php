<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\AdminRole;
use App\Repository\AdminUserRepository;

/**
 * Admin session handling: password check, lockout, idle and absolute timeouts,
 * and the role/company scope every admin screen authorises against.
 */
final class Auth
{
    private const KEY_ID       = '_admin_id';
    private const KEY_LOGIN_AT = '_admin_login_at';
    private const KEY_SEEN_AT  = '_admin_seen_at';

    private const MAX_FAILED   = 10;
    private const LOCK_MINUTES = 15;

    /** @var array<string, mixed>|null Per-request cache; not a cross-request store. */
    private static ?array $cached = null;

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * The signed-in account, or null.
     *
     * Only the id lives in the session; role, company and is_active are read
     * from the database once per request. Caching privileges in the session
     * would mean that revoking a role, moving someone to another company or
     * deactivating an account had no effect until they happened to log out -
     * which is exactly when it matters most. One primary-key lookup is a
     * cheap price for that.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        SessionManager::start();
        $id = SessionManager::get(self::KEY_ID);
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        $now      = time();
        $loginAt  = (int) SessionManager::get(self::KEY_LOGIN_AT, 0);
        $seenAt   = (int) SessionManager::get(self::KEY_SEEN_AT, 0);
        $idleMax  = Config::int('session.idle_timeout', 1800);
        $absMax   = Config::int('session.absolute_timeout', 28800);

        if ($now - $seenAt > $idleMax || $now - $loginAt > $absMax) {
            self::logout();
            return null;
        }

        $row = (new AdminUserRepository())->find($id);
        if ($row === null || (int) $row['is_active'] !== 1) {
            self::logout();
            return null;
        }

        SessionManager::set(self::KEY_SEEN_AT, $now);

        self::$cached = [
            'id'           => (int) $row['id'],
            'username'     => (string) $row['username'],
            'display_name' => (string) $row['display_name'],
            'role'         => AdminRole::from((string) $row['role']),
            'company_id'   => $row['company_id'] !== null ? (int) $row['company_id'] : null,
        ];
        return self::$cached;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function role(): ?AdminRole
    {
        return self::user()['role'] ?? null;
    }

    public static function isSuperadmin(): bool
    {
        return self::role() === AdminRole::Superadmin;
    }

    /**
     * The company this account is confined to, or null for the office.
     * Null means "no restriction", never "company 0" - callers must not
     * silently turn it into an integer.
     */
    public static function companyId(): ?int
    {
        return self::user()['company_id'] ?? null;
    }

    /** "admin:<username>", used as the actor on booking_events rows. */
    public static function actor(): string
    {
        $user = self::user();
        return $user === null ? 'system' : 'admin:' . $user['username'];
    }

    /**
     * @return bool True on success. Failure is deliberately indistinguishable
     *              between "no such user" and "wrong password".
     */
    public static function attempt(string $username, string $password): bool
    {
        $repo = new AdminUserRepository();
        $user = $repo->findByUsername($username);

        // Hash even when the user does not exist, so response time does not
        // reveal which usernames are real.
        $hash = $user['password_hash'] ?? '$2y$12$'. str_repeat('.', 53);

        if ($user !== null && $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            return false;
        }

        $ok = password_verify($password, (string) $hash);

        if ($user === null || !$ok || (int) $user['is_active'] !== 1) {
            if ($user !== null) {
                $repo->registerFailure((int) $user['id'], self::MAX_FAILED, self::LOCK_MINUTES);
            }
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT, ['cost' => 12])) {
            $repo->updatePassword((int) $user['id'], self::hashPassword($password));
        }

        $repo->registerSuccess((int) $user['id']);

        SessionManager::start();
        // New privilege level, new session ID.
        SessionManager::regenerate();
        Csrf::rotate();

        SessionManager::set(self::KEY_ID, (int) $user['id']);
        SessionManager::set(self::KEY_LOGIN_AT, time());
        SessionManager::set(self::KEY_SEEN_AT, time());
        self::$cached = null;

        return true;
    }

    public static function logout(): void
    {
        self::$cached = null;
        SessionManager::start();
        SessionManager::forget(self::KEY_ID);
        SessionManager::forget(self::KEY_LOGIN_AT);
        SessionManager::forget(self::KEY_SEEN_AT);
        SessionManager::regenerate();
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }
}
