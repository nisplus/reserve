<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/** Rendered as a 404. */
class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'ページが見つかりません。')
    {
        parent::__construct($message);
    }
}
