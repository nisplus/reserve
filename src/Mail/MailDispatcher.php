<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use App\Core\Db;
use App\Repository\MailQueueRepository;
use Throwable;

/**
 * Drains the transactional outbox (mail_queue).
 *
 * Two callers share this: bin/send_mail.php (the reliable path) and the
 * best-effort dispatch controllers run right after a booking or cancellation
 * commits (so the confirmation lands in seconds, not at the next CLI run).
 * Each message is claimed under a row lock, so the two paths cannot
 * double-send; see MailQueueRepository::lockPending().
 *
 * A failed send leaves the row pending with the error recorded, to be retried
 * until MAX_ATTEMPTS, after which it is parked as 'failed' for an admin to
 * inspect (stage 8 gets a screen for that).
 */
final class MailDispatcher
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly MailQueueRepository $queue = new MailQueueRepository(),
        private readonly ?MailerInterface $mailer = null,
    ) {
    }

    /** @return array{sent: int, failed: int, skipped: int} */
    public function processPending(int $limit = 50): array
    {
        $mailer   = $this->mailer ?? MailerFactory::make();
        $fromMail = Config::string('mail.from.address');
        $fromName = Config::string('mail.from.name');

        $sent = $failed = $skipped = 0;

        foreach ($this->queue->pendingIds($limit) as $id) {
            $outcome = Db::transaction(function () use ($id, $mailer, $fromMail, $fromName): string {
                $row = $this->queue->lockPending($id);
                if ($row === null) {
                    return 'skipped'; // another worker got here first
                }

                try {
                    $mailer->send(new MailMessage(
                        (string) $row['to_email'],
                        $row['to_name'] !== null ? (string) $row['to_name'] : null,
                        (string) $row['subject'],
                        (string) $row['body'],
                        $fromMail,
                        $fromName,
                    ));
                } catch (Throwable $e) {
                    // The failure is data, not an exception: record it and
                    // commit, or the attempt counter would roll back with it.
                    $this->queue->markFailure($id, $e->getMessage(), self::MAX_ATTEMPTS);
                    return 'failed';
                }

                $this->queue->markSent($id);
                return 'sent';
            });

            match ($outcome) {
                'sent'    => $sent++,
                'failed'  => $failed++,
                'skipped' => $skipped++,
            };
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Post-commit hook for the web flow. Best effort by contract: the booking
     * has already committed, so nothing here may break the user's response -
     * on any failure the mail simply stays queued for bin/send_mail.php.
     */
    public static function tryProcessPending(int $limit = 10): void
    {
        try {
            (new self())->processPending($limit);
        } catch (Throwable) {
            // Deliberately swallowed; the queue is the safety net.
        }
    }
}
