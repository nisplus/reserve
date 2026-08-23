<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

final class AdminUserRepository
{
    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return Db::selectOne('SELECT * FROM admin_users WHERE username = ?', [$username]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }

    public function create(string $username, string $passwordHash, string $displayName): int
    {
        Db::execute(
            'INSERT INTO admin_users (username, password_hash, display_name) VALUES (?, ?, ?)',
            [$username, $passwordHash, $displayName]
        );
        return Db::lastInsertId();
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        Db::execute(
            'UPDATE admin_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [$passwordHash, $id]
        );
    }

    /** Count the failure and lock the account once the threshold is crossed. */
    public function registerFailure(int $id, int $maxAttempts, int $lockMinutes): void
    {
        Db::execute(
            'UPDATE admin_users
                SET failed_attempts = failed_attempts + 1,
                    locked_until = CASE WHEN failed_attempts + 1 >= ?
                                        THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                                        ELSE locked_until END
              WHERE id = ?',
            [$maxAttempts, $lockMinutes, $id]
        );
    }

    public function registerSuccess(int $id): void
    {
        Db::execute(
            'UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?',
            [$id]
        );
    }
}
