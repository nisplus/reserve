<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Development transport: one .eml per message under storage/mail.
 *
 * The files are complete RFC 5322 messages (CRLF, base64 body), so
 * double-clicking one opens it in a mail client exactly as the recipient
 * would see it - which is the point: checking Japanese subjects and bodies
 * render correctly is a stage-6 acceptance test.
 */
final class FileMailer implements MailerInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    public function send(MailMessage $message): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Mail directory cannot be created: {$this->dir}");
        }

        // Sortable timestamp plus randomness: two messages in the same second
        // (a booking's applicant + admin pair) must not collide.
        $file = $this->dir . DIRECTORY_SEPARATOR
              . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';

        if (file_put_contents($file, $message->compile()) === false) {
            throw new RuntimeException("Failed to write mail file: {$file}");
        }
    }
}
