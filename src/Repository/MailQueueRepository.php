<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Db;

/**
 * Transactional outbox. Rows are inserted inside the same transaction as the
 * change they announce, so a rolled-back booking can never leave a "you are
 * confirmed" e-mail behind. Actual delivery (stage 6) reads pending rows and
 * marks them sent or failed.
 */
final class MailQueueRepository
{
    /** A message tied to one booking - the kind a person is waiting for. */
    public const TRANSACTIONAL = 'transactional';

    /** One message of a campaign sent to many people at once. */
    public const BULK = 'bulk';

    public function enqueue(
        string $toEmail,
        ?string $toName,
        string $subject,
        string $body,
        ?int $bookingId = null,
        string $category = self::TRANSACTIONAL,
    ): int {
        Db::execute(
            'INSERT INTO mail_queue (to_email, to_name, subject, body, booking_id, category)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$toEmail, $toName, $subject, $body, $bookingId, $category]
        );
        return Db::lastInsertId();
    }

    /**
     * Oldest-first batch of ids worth attempting.
     *
     * $category narrows it. The inline send that runs at the end of a booking
     * passes TRANSACTIONAL so a queued campaign cannot make the person who
     * just booked wait for other people's mail; cron and the admin button
     * pass null and drain everything.
     *
     * @return array<int, int>
     */
    public function pendingIds(int $limit, ?string $category = null): array
    {
        $where = $category !== null ? 'AND category = ?' : '';
        $statement = Db::pdo()->prepare(
            "SELECT id FROM mail_queue WHERE status = 'pending' {$where} ORDER BY id LIMIT ?"
        );
        $position = 1;
        if ($category !== null) {
            $statement->bindValue($position++, $category);
        }
        // Native prepares reject a string for LIMIT; it must be bound as an int.
        $statement->bindValue($position, $limit, \PDO::PARAM_INT);
        $statement->execute();
        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * How many are still waiting. Shown on the admin button and reported
     * back after a drain, because a campaign is sent in batches and the
     * operator needs to know whether they are finished.
     */
    public function countPending(): int
    {
        return (int) Db::scalar("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'");
    }

    /**
     * Claim one message for sending. Locking the row and re-checking status
     * is what lets the CLI worker and the post-commit inline dispatch coexist
     * without double-sending: whoever locks first sends, the other sees the
     * row is no longer pending and skips. Call inside a transaction.
     *
     * @return array<string, mixed>|null
     */
    public function lockPending(int $id): ?array
    {
        return Db::selectOne(
            "SELECT id, to_email, to_name, subject, body, attempts
             FROM mail_queue WHERE id = ? AND status = 'pending' FOR UPDATE",
            [$id]
        );
    }

    public function markSent(int $id): void
    {
        Db::execute(
            "UPDATE mail_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1,
                    last_error = NULL
             WHERE id = ?",
            [$id]
        );
    }

    /** Failed attempts stay pending (retried by the next run) until the cap. */
    public function markFailure(int $id, string $error, int $maxAttempts): void
    {
        Db::execute(
            "UPDATE mail_queue
             SET attempts = attempts + 1,
                 last_error = ?,
                 status = IF(attempts + 1 >= ?, 'failed', 'pending')
             WHERE id = ?",
            [mb_substr($error, 0, 500), $maxAttempts, $id]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return Db::selectOne('SELECT * FROM mail_queue WHERE id = ?', [$id]);
    }

    /**
     * Newest-first admin listing, optionally by status and by category.
     *
     * The category filter is what keeps a campaign readable: 300 queued
     * announcements otherwise bury every booking confirmation sent the
     * same afternoon, and those are the ones somebody is waiting for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(string $status, int $limit, int $offset, string $category = ''): array
    {
        [$where, $params] = self::filter($status, $category);
        $statement = Db::pdo()->prepare(
            "SELECT id, to_email, to_name, subject, status, attempts, last_error,
                    booking_id, category, created_at, sent_at
             FROM mail_queue {$where}
             ORDER BY id DESC
             LIMIT ? OFFSET ?"
        );
        $position = 1;
        foreach ($params as $param) {
            $statement->bindValue($position++, $param);
        }
        $statement->bindValue($position++, $limit, \PDO::PARAM_INT);
        $statement->bindValue($position, $offset, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function countForAdmin(string $status, string $category = ''): int
    {
        [$where, $params] = self::filter($status, $category);
        return (int) Db::scalar("SELECT COUNT(*) FROM mail_queue {$where}", $params);
    }

    /**
     * The WHERE both admin queries share, so the total and the page
     * cannot disagree about what is being listed.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private static function filter(string $status, string $category): array
    {
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Put a parked (failed) message back in line with a fresh attempt budget.
     * Returns false when the row was not failed - resending an already-sent
     * mail must be an explicit decision, not a stray double-click.
     */
    public function requeueFailed(int $id): bool
    {
        return Db::execute(
            "UPDATE mail_queue
             SET status = 'pending', attempts = 0, last_error = NULL
             WHERE id = ? AND status = 'failed'",
            [$id]
        ) === 1;
    }
}
