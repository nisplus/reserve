<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Random identifiers shown to (or mailed to) the public.
 *
 * The cancel token is the only credential an applicant ever holds: whoever has
 * it can view and cancel the booking. The database stores only its SHA-256
 * hash, so a leaked database does not hand out cancellation rights.
 */
final class TokenService
{
    /**
     * 64 hex chars for the URL, plus the hash that goes into the database.
     * The raw value must never be persisted - it exists in the confirmation
     * e-mail and nowhere else.
     *
     * @return array{raw: string, hash: string}
     */
    public static function newCancelToken(): array
    {
        $raw = bin2hex(random_bytes(32));
        return ['raw' => $raw, 'hash' => self::hashToken($raw)];
    }

    /** How a raw token from a URL is matched against bookings.cancel_token_hash. */
    public static function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Public booking number. AUTO_INCREMENT ids leak gaps (rollbacks) and
     * volume, so the reference shown to people is independent randomness.
     * 12 hex chars = 48 bits; collisions are handled by the UNIQUE index and
     * the transaction retry, not by checking first.
     */
    public static function newReferenceCode(): string
    {
        return bin2hex(random_bytes(6));
    }
}
