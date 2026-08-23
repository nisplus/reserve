<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Collects field errors and raises them in one go, so a form can be redisplayed
 * with everything wrong about it at once.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $values = [];

    public function required(string $field, string $label, string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $this->fail($field, "{$label}を入力してください。");
        }
        $this->values[$field] = $trimmed;
        return $this;
    }

    /** Length is counted in characters; VARCHAR(n) in MariaDB is characters too. */
    public function maxLength(string $field, string $label, string $value, int $max): self
    {
        if (mb_strlen($value) > $max) {
            return $this->fail($field, "{$label}は{$max}文字以内で入力してください。");
        }
        return $this;
    }

    public function email(string $field, string $label, string $value): self
    {
        $normalized = self::normalizeEmail($value);
        if ($normalized === '') {
            return $this->fail($field, "{$label}を入力してください。");
        }
        if (mb_strlen($normalized) > 255) {
            return $this->fail($field, "{$label}は255文字以内で入力してください。");
        }
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return $this->fail($field, "{$label}の形式が正しくありません。");
        }
        // A newline here would let an attacker inject extra mail headers.
        if (preg_match('/[\r\n]/', $normalized)) {
            return $this->fail($field, "{$label}に使用できない文字が含まれています。");
        }
        $this->values[$field] = $normalized;
        return $this;
    }

    public function intRange(string $field, string $label, string $value, int $min, int $max): self
    {
        if (!preg_match('/^\d+$/', trim($value))) {
            return $this->fail($field, "{$label}は数字で入力してください。");
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            return $this->fail($field, "{$label}は{$min}〜{$max}の範囲で入力してください。");
        }
        $this->values[$field] = $number;
        return $this;
    }

    /** Accepts "Y-m-d H:i" and "Y-m-d\TH:i" (what datetime-local posts). */
    public function datetime(string $field, string $label, string $value): self
    {
        $trimmed = str_replace('T', ' ', trim($value));
        if ($trimmed === '') {
            return $this->fail($field, "{$label}を入力してください。");
        }
        if (strlen($trimmed) === 16) {
            $trimmed .= ':00';
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed);
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $trimmed) {
            return $this->fail($field, "{$label}の日時形式が正しくありません（例: 2026-09-01 10:00）。");
        }
        $this->values[$field] = $parsed->format('Y-m-d H:i:s');
        return $this;
    }

    /** @param array<int, string> $allowed */
    public function inList(string $field, string $label, string $value, array $allowed): self
    {
        if (!in_array($value, $allowed, true)) {
            return $this->fail($field, "{$label}の選択が不正です。");
        }
        $this->values[$field] = $value;
        return $this;
    }

    public function optional(string $field, string $value, int $max): self
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && mb_strlen($trimmed) > $max) {
            return $this->fail($field, "{$max}文字以内で入力してください。");
        }
        $this->values[$field] = $trimmed === '' ? null : $trimmed;
        return $this;
    }

    public function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function value(string $field, mixed $default = null): mixed
    {
        return $this->values[$field] ?? $default;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @throws ValidationException */
    public function validate(): void
    {
        if ($this->hasErrors()) {
            throw ValidationException::fields($this->errors);
        }
    }

    /**
     * Trim and lower-case. The applicants table is utf8mb4_unicode_ci so the
     * UNIQUE index is already case-insensitive, but normalising on the way in
     * keeps what we display consistent with what we matched on.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}
