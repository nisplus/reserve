<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Config;
use RuntimeException;

/**
 * Builds the transport named by mail.transport: 'file' during development,
 * 'smtp' in production. One config line switches the whole system over.
 */
final class MailerFactory
{
    public static function make(): MailerInterface
    {
        $transport = Config::string('mail.transport', 'file');

        return match ($transport) {
            'file' => new FileMailer(
                Config::string('mail.file.dir', APP_ROOT . '/storage/mail')
            ),
            'smtp' => new SmtpMailer(
                Config::string('mail.smtp.host'),
                Config::int('mail.smtp.port', 587),
                Config::string('mail.smtp.encryption', 'tls'),
                Config::string('mail.smtp.username'),
                Config::string('mail.smtp.password'),
                Config::int('mail.smtp.timeout', 10),
            ),
            default => throw new RuntimeException("Unknown mail transport: {$transport}"),
        };
    }
}
