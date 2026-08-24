<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * One outgoing message, plus the compiler that turns it into an RFC 5322
 * byte stream. Both transports send the exact same bytes: FileMailer writes
 * them to a .eml, SmtpMailer feeds them to DATA.
 *
 * Everything header-bound is sanitised here: a CR or LF smuggled into an
 * address or subject would otherwise let the sender inject extra headers.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $fromEmail,
        public readonly string $fromName,
    ) {
    }

    /**
     * The complete message, CRLF line endings throughout. .eml consumers
     * (Outlook in particular) reject or mis-render bare LF, and SMTP requires
     * CRLF outright, so it is normalised once here.
     */
    public function compile(): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::formatAddress($this->fromEmail, $this->fromName),
            'To: ' . self::formatAddress($this->toEmail, $this->toName),
            'Subject: ' . self::encodeHeader(self::stripBreaks($this->subject)),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->fromDomain() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            // Base64 keeps the payload 7-bit clean, so the file survives mail
            // servers and clients that mangle raw UTF-8.
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers)
            . "\r\n\r\n"
            . chunk_split(base64_encode($this->body), 76, "\r\n");
    }

    /** '"名前" <addr>' with the display name RFC 2047-encoded when needed. */
    private static function formatAddress(string $email, ?string $name): string
    {
        $email = self::stripBreaks($email);
        if ($name === null || trim($name) === '') {
            return $email;
        }
        return self::encodeHeader(self::stripBreaks($name)) . ' <' . $email . '>';
    }

    /**
     * RFC 2047 B-encoding for Japanese header text. ASCII-only input passes
     * through unchanged. mb_encode_mimeheader reads mb_internal_encoding for
     * the input charset, which bootstrap.php pins to UTF-8.
     */
    private static function encodeHeader(string $value): string
    {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    /** Header values must be single-line; see the class comment. */
    private static function stripBreaks(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function fromDomain(): string
    {
        $at = strrchr($this->fromEmail, '@');
        return $at !== false && strlen($at) > 1 ? substr($at, 1) : 'localhost';
    }
}
