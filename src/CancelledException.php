<?php

declare(strict_types=1);

namespace Vow;

use RuntimeException;
use Throwable;

final class CancelledException extends RuntimeException
{
    public function __construct(string $message = 'Promise was cancelled', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
