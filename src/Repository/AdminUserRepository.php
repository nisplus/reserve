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

    /**
     * @param string   $role      'superadmin' or 'company'
     * @param int|null $companyId Required for 'company', must be null otherwise
     *                            (chk_admin_users_scope enforces the pairing).
     */
    public function create(
        string $username,
        string $passwordHash,
        string $displayName,
        string $role = 'superadmin',
        ?int $companyId = null,
    ): int {
        Db::execute(
            'INSERT INTO admin_users (username, password_hash, display_name, role, company_id)
             VALUES (?, ?, ?, ?, ?)',
            [$username, $passwordHash, $displayName, $role, $companyId]
        );
        return Db::lastInsertId();
    }

    /** Accounts with their company name, newest last. @return array<int, array<string, mixed>> */
    public function listAll(): array
    {
        return Db::select(
            'SELECT u.id, u.username, u.display_name, u.role, u.company_id, u.is_active,
                    u.locked_until, u.last_login_at, u.created_at,
                    c.name AS company_name
             FROM admin_users u
             LEFT JOIN companies c ON c.id = u.company_id
             ORDER BY u.role, c.sort_order, c.id, u.id'
        );
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return (int) Db::scalar('SELECT COUNT(*) FROM admin_users WHERE username = ?', [$username]) > 0;
        }
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM admin_users WHERE username = ? AND id <> ?',
            [$username, $exceptId]
        ) > 0;
    }

    public function updateProfile(int $id, string $displayName, string $role, ?int $companyId): void
    {
        Db::execute(
            'UPDATE admin_users SET display_name = ?, role = ?, company_id = ? WHERE id = ?',
            [$displayName, $role, $companyId, $id]
        );
    }

    /** Deactivating also clears the lockout, so re-enabling is a single step. */
    public function setActive(int $id, bool $active): void
    {
        Db::execute(
            'UPDATE admin_users SET is_active = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [$active ? 1 : 0, $id]
        );
    }

    /** How many usable office accounts exist - the last one must not be removed. */
    public function activeSuperadminCount(): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin' AND is_active = 1"
        );
    }

    public function countForCompany(int $companyId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM admin_users WHERE company_id = ?', [$companyId]);
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
