<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

interface MailerInterface
{
    /** @throws RuntimeException when the message could not be handed off */
    public function send(MailMessage $message): void;
}
