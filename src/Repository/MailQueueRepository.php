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
    public function enqueue(string $toEmail, ?string $toName, string $subject, string $body, ?int $bookingId = null): int
    {
        Db::execute(
            'INSERT INTO mail_queue (to_email, to_name, subject, body, booking_id) VALUES (?, ?, ?, ?, ?)',
            [$toEmail, $toName, $subject, $body, $bookingId]
        );
        return Db::lastInsertId();
    }
}
