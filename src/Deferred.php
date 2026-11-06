<?php

declare(strict_types=1);

namespace Vow;

use Throwable;

/**
 * The only way to settle a pending promise. Keep it with the operation and hand out $promise to consumers.
 *
 * @template T
 */
final class Deferred
{
    /** @var Promise<T> */
    public readonly Promise $promise;

    public function __construct()
    {
        $this->promise = new Promise();
    }

    /**
     * @param T $value
     * @throws \LogicException if the promise was already settled, unless it was cancelled
     */
    public function resolve(mixed $value): void
    {
        $this->promise->settle(true, $value);
    }

    /**
     * @throws \LogicException if the promise was already settled, unless it was cancelled
     */
    public function reject(Throwable $reason): void
    {
        $this->promise->settle(false, $reason);
    }

    /**
     * Cancels a pending promise and ignores later results.
     * Cancelled promises are never reported as unhandled.
     */
    public function cancel(?CancelledException $reason = null): void
    {
        if ($this->promise->isPending()) {
            $this->promise->settle(false, $reason ?? new CancelledException());
        }
    }
}
