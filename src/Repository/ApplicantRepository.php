<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

final class ApplicantRepository
{
    /**
     * Find-or-create by e-mail and return the id, in one statement.
     *
     * ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) makes lastInsertId()
     * return the existing row's id when the e-mail is already known, so both
     * paths come back through the same call. Runs outside the booking
     * transaction on purpose: creating the row needs no lock, and doing it
     * early keeps the locked section as short as possible.
     *
     * The caller must pass an already-normalised address (Validator::normalizeEmail);
     * the UNIQUE index is case-insensitive either way, but what we store should
     * match what we matched on.
     */
    public function idForEmail(string $email): int
    {
        Db::execute(
            'INSERT INTO applicants (email) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$email]
        );
        return Db::lastInsertId();
    }

    /**
     * Read-only lookup for advisory checks (the confirmation screen's travel
     * warning). Unlike idForEmail this creates nothing: someone merely LOOKING
     * at a form should not leave an applicant row behind.
     */
    public function findIdByEmail(string $email): ?int
    {
        $id = Db::scalar('SELECT id FROM applicants WHERE email = ?', [$email]);
        return $id !== null ? (int) $id : null;
    }

    /**
     * The applicant gate: locking this row serialises every booking operation
     * by the same person. Overlap is a range comparison no UNIQUE index can
     * express, and rows that do not exist yet cannot be locked - this single
     * parent row is what stands in for both. Must be called inside a
     * transaction, and always FIRST in the lock order
     * (applicants -> event_sessions -> bookings).
     */
    public function lock(int $id): bool
    {
        return Db::selectOne('SELECT id FROM applicants WHERE id = ? FOR UPDATE', [$id]) !== null;
    }
}
