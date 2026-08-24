<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Minimal SMTP client over stream sockets: EHLO, STARTTLS, AUTH LOGIN,
 * MAIL/RCPT/DATA.
 *
 * Hand-rolled on purpose. PHP's mail() is unusable here: the Windows build
 * speaks only unauthenticated SMTP via php.ini and cannot do STARTTLS, and on
 * Linux it shells out to sendmail. The protocol itself is small - the risky
 * parts (RFC 2047 headers, base64 bodies) live in MailMessage, not here.
 *
 * Supported encryption modes: 'tls' (connect plain, upgrade via STARTTLS),
 * 'ssl' (implicit TLS from the first byte), 'none'.
 */
final class SmtpMailer implements MailerInterface
{
    /** @var resource|null */
    private $socket;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 10,
    ) {
        $this->socket = null;
    }

    public function send(MailMessage $message): void
    {
        if ($this->host === '') {
            throw new RuntimeException('SMTP host is not configured (mail.smtp.host).');
        }

        try {
            $this->connect();
            $this->hello();

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }
                $this->hello(); // the session state resets after the TLS upgrade
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334]);
                $this->command(base64_encode($this->password), [235]);
            }

            $this->command('MAIL FROM:<' . $message->fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $message->toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);

            // Transparency (dot-stuffing): a body line starting with "." would
            // otherwise end the DATA phase early.
            $payload = preg_replace('/(^|\r\n)\./', '$1..', $message->compile());
            $this->write($payload . "\r\n.");
            $this->expect([250]);

            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
            $errno,
            $error,
            $this->timeout
        );
        if ($socket === false) {
            throw new RuntimeException("SMTP connection failed: {$error} ({$errno})");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->expect([220]);
    }

    private function hello(): void
    {
        $host = gethostname();
        $this->command('EHLO ' . ($host !== false && $host !== '' ? $host : 'localhost'), [250]);
    }

    /** @param array<int, int> $expectCodes */
    private function command(string $line, array $expectCodes): string
    {
        $this->write($line);
        return $this->expect($expectCodes);
    }

    private function write(string $line): void
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new RuntimeException('SMTP write failed.');
        }
    }

    /**
     * Read one (possibly multi-line) reply. Continuation lines look like
     * "250-..."; the final line is "250 ...".
     *
     * @param array<int, int> $expectCodes
     */
    private function expect(array $expectCodes): string
    {
        $reply = '';
        do {
            $line = fgets($this->socket, 2048);
            if ($line === false) {
                throw new RuntimeException('SMTP read failed (timeout or closed connection).');
            }
            $reply .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $expectCodes, true)) {
            throw new RuntimeException('SMTP unexpected reply: ' . trim($reply));
        }
        return $reply;
    }
}
