<?php

declare(strict_types=1);

namespace Vow;

use RuntimeException;
use Throwable;

use function count;

/**
 * Thrown by Promise::any() when every promise rejected.
 */
final class AggregateException extends RuntimeException
{
    /**
     * @param array<array-key, Throwable> $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(count($reasons) === 0 ? 'No promises were given' : 'All ' . count($reasons) . ' promises were rejected');
    }
}
