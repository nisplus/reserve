<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Input the user can correct. Carries per-field messages so a form can be
 * redisplayed with the errors attached to the right inputs.
 */
class ValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param array<string, string> $errors */
    public static function fields(array $errors): self
    {
        return new self('入力内容をご確認ください。', $errors);
    }
}
