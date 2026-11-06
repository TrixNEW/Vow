<?php

declare(strict_types=1);

namespace Vow;

use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use Throwable;
use ValueError;
use WeakMap;

use function count;

/**
 * A read-only view of a value that may not be available yet.
 *
 * Callbacks run synchronously: when the promise settles they run before the settling call returns, and when it
 * is already settled they run before the registering call returns. A promise's callbacks always run in the
 * order they were registered, and derived promises settle only after their parent finished its own callbacks.
 *
 * Pending promises come from a Deferred, which is the only way to settle them.
 *
 * @template-covariant T
 */
final class Promise
{
    private const int PENDING = 0;
    private const int FULFILLED = 1;
    private const int REJECTED = 2;

    private int $state = self::PENDING;

    /** The value, the rejection reason, or the promise being followed while pending. */
    private mixed $result = null;

    /**
     * Flat [onFulfilled, onRejected, child] triples, null once the settled promise has run them all.
     *
     * @var list<Closure|Promise<mixed>|null>|null
     */
    private ?array $handlers = [];

    private ?UnhandledRejection $unhandled = null;

    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, Promise<mixed>>|null */
    private static ?WeakMap $fibers = null;

    /**
     * @template V
     * @param V $value
     * @return Promise<V>
     */
    public static function resolved(mixed $value): Promise
    {
        if ($value instanceof self) {
            throw new InvalidArgumentException('Cannot resolve a promise with another promise');
        }
        $promise = new self();
        $promise->state = self::FULFILLED;
        $promise->result = $value;
        $promise->handlers = null;
        return $promise;
    }

    /**
     * @return Promise<never>
     */
    public static function rejected(Throwable $reason): Promise
    {
        $promise = new self();
        $promise->state = self::REJECTED;
        $promise->result = $reason;
        $promise->handlers = null;
        $promise->track();
        return $promise;
    }

    /**
     * Sets the callback for rejections nobody handled, returning the previous one. Reports happen when the last
     * reference to the rejected promise goes away. Pass null to restore the default, which writes to error_log().
     *
     * @param (Closure(Throwable): void)|null $handler
     * @return (Closure(Throwable): void)|null
     */
    public static function setRejectionHandler(?Closure $handler): ?Closure
    {
        $previous = UnhandledRejection::$handler;
        UnhandledRejection::$handler = $handler;
        return $previous;
    }

    /**
     * Returns a promise for the callbacks' result, adopting it when a callback returns a promise. A missing
     * callback passes the outcome through, and a callback that throws rejects the returned promise.
     *
     * @template TFulfilled
     * @template TRejected
     * @param (Closure(T): (TFulfilled|Promise<TFulfilled>))|null $onFulfilled
     * @param (Closure(Throwable): (TRejected|Promise<TRejected>))|null $onRejected
     * @return Promise<($onFulfilled is null ? T : TFulfilled)|($onRejected is null ? never : TRejected)>
     */
    public function then(?Closure $onFulfilled = null, ?Closure $onRejected = null): Promise
    {
        $child = new self();
        if ($this->handlers !== null) {
            $this->handlers[] = $onFulfilled;
            $this->handlers[] = $onRejected;
            $this->handlers[] = $child;
            return $child;
        }
        if ($this->unhandled !== null) {
            $this->handle();
        }
        if (self::invoke($this->state === self::FULFILLED, $this->result, $onFulfilled, $onRejected, $child)) {
            $child->handlers = null;
            if ($child->state === self::REJECTED) {
                $child->track();
            }
        }
        return $child;
    }

    /**
     * @template TRejected
     * @param Closure(Throwable): (TRejected|Promise<TRejected>) $onRejected
     * @return Promise<T|TRejected>
     */
    public function catch(Closure $onRejected): Promise
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Runs the callback once settled and passes the original outcome through, unless the callback throws or
     * returns a promise that rejects. A returned promise is waited for first.
     *
     * @param Closure(): mixed $onSettled
     * @return Promise<T>
     */
    public function finally(Closure $onSettled): Promise
    {
        return $this->then(
            static function (mixed $value) use ($onSettled): mixed {
                $result = $onSettled();
                return $result instanceof self ? $result->then(static fn(): mixed => $value) : $value;
            },
            static function (Throwable $reason) use ($onSettled): Promise {
                $result = $onSettled();
                if ($result instanceof self) {
                    return $result->then(static fn(): never => throw $reason);
                }
                throw $reason;
            },
        );
    }

    /**
     * Listens for the outcome without creating another promise. This is the cheapest way to consume a promise.
     * If a callback throws, the remaining callbacks still run and the first exception is rethrown to whoever
     * settled the promise, or straight to the caller when the promise was already settled.
     *
     * @param (Closure(T): mixed)|null $onFulfilled
     * @param (Closure(Throwable): mixed)|null $onRejected
     */
    public function observe(?Closure $onFulfilled, ?Closure $onRejected = null): void
    {
        if ($this->handlers !== null) {
            $this->handlers[] = $onFulfilled;
            $this->handlers[] = $onRejected;
            $this->handlers[] = null;
        } elseif ($this->state === self::FULFILLED) {
            if ($onFulfilled !== null) {
                $onFulfilled($this->result);
            }
        } elseif ($onRejected !== null && ($reason = $this->result) instanceof Throwable) {
            if ($this->unhandled !== null) {
                $this->handle();
            }
            $onRejected($reason);
        }
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    /**
     * Returns the value, or throws the rejection reason.
     *
     * @return T
     * @throws Throwable the rejection reason, or a LogicException if the promise is still pending
     */
    public function value(): mixed
    {
        if ($this->state === self::FULFILLED) {
            return $this->result;
        }
        throw $this->reason() ?? new LogicException('Promise is still pending');
    }

    /**
     * Returns the rejection reason, or null unless the promise was rejected.
     */
    public function reason(): ?Throwable
    {
        $reason = $this->result;
        if ($this->state !== self::REJECTED || !$reason instanceof Throwable) {
            return null;
        }
        $this->handle();
        return $reason;
    }

    /**
     * Returns the value, or throws the rejection reason. A pending promise suspends the current Promise::async()
     * fiber until it settles.
     *
     * @return T
     * @throws Throwable the rejection reason, or a LogicException if pending outside Promise::async()
     */
    public function await(): mixed
    {
        if ($this->state !== self::PENDING) {
            return $this->value();
        }
        $fiber = Fiber::getCurrent();
        if ($fiber === null || !isset(self::$fibers[$fiber])) {
            throw new LogicException('A pending promise can only be awaited inside Promise::async()');
        }
        $this->observe(
            static function (mixed $value) use ($fiber): void {
                self::step($fiber, static fn(): mixed => $fiber->resume($value));
            },
            static function (Throwable $reason) use ($fiber): void {
                self::step($fiber, static fn(): mixed => $fiber->throw($reason));
            },
        );
        return Fiber::suspend();
    }

    /**
     * Runs the function in a fiber that can await() pending promises, and returns a promise for its result.
     * The function runs right away until its first await() on a pending promise.
     *
     * @template R
     * @param Closure(): (R|Promise<R>) $function
     * @return Promise<R>
     */
    public static function async(Closure $function): Promise
    {
        $promise = new self();
        $fiber = new Fiber($function);
        self::$fibers ??= new WeakMap();
        self::$fibers[$fiber] = $promise;
        self::step($fiber, static fn(): mixed => $fiber->start());
        return $promise;
    }

    /**
     * Fulfils with every value under its original key once all fulfil, or rejects with the first rejection.
     *
     * @template K of array-key
     * @template V
     * @param array<K, Promise<V>> $promises
     * @return Promise<array<K, V>>
     */
    public static function all(array $promises): Promise
    {
        $remaining = count($promises);
        if ($remaining === 0) {
            return self::resolved([]);
        }
        $result = new self();
        $values = $promises;
        $onRejected = static function (Throwable $reason) use ($result): void {
            if ($result->state === self::PENDING) {
                $result->settle(false, $reason);
            }
        };
        foreach ($promises as $key => $promise) {
            $promise->observe(
                static function (mixed $value) use ($key, &$values, &$remaining, $result): void {
                    if ($result->state !== self::PENDING) {
                        return;
                    }
                    $values[$key] = $value;
                    if (--$remaining === 0) {
                        $result->settle(true, $values);
                        $values = [];
                    }
                },
                $onRejected,
            );
            if ($result->state !== self::PENDING) {
                break;
            }
        }
        return $result;
    }

    /**
     * Fulfils once every promise has settled, with the same array of promises, now all settled.
     *
     * @template K of array-key
     * @template P of Promise<mixed>
     * @param array<K, P> $promises
     * @return Promise<array<K, P>>
     */
    public static function allSettled(array $promises): Promise
    {
        $remaining = count($promises);
        if ($remaining === 0) {
            return self::resolved([]);
        }
        $result = new self();
        $onSettled = static function () use (&$remaining, $result, $promises): void {
            if (--$remaining === 0) {
                $result->settle(true, $promises);
            }
        };
        foreach ($promises as $promise) {
            $promise->observe($onSettled, $onSettled);
        }
        return $result;
    }

    /**
     * Settles like the first promise to settle.
     *
     * @template V
     * @param array<Promise<V>> $promises
     * @return Promise<V>
     * @throws ValueError if no promises are given, since the result could never settle
     */
    public static function race(array $promises): Promise
    {
        if ($promises === []) {
            throw new ValueError('Cannot race an empty array of promises');
        }
        $result = new self();
        $onFulfilled = static function (mixed $value) use ($result): void {
            if ($result->state === self::PENDING) {
                $result->settle(true, $value);
            }
        };
        $onRejected = static function (Throwable $reason) use ($result): void {
            if ($result->state === self::PENDING) {
                $result->settle(false, $reason);
            }
        };
        foreach ($promises as $promise) {
            $promise->observe($onFulfilled, $onRejected);
            if ($result->state !== self::PENDING) {
                break;
            }
        }
        return $result;
    }

    /**
     * Fulfils like the first promise to fulfil, or rejects with an AggregateException holding every reason
     * under its original key once all of them reject.
     *
     * @template K of array-key
     * @template V
     * @param array<K, Promise<V>> $promises
     * @return Promise<V>
     */
    public static function any(array $promises): Promise
    {
        $remaining = count($promises);
        if ($remaining === 0) {
            return self::rejected(new AggregateException([]));
        }
        $result = new self();
        $reasons = [];
        $onFulfilled = static function (mixed $value) use ($result): void {
            if ($result->state === self::PENDING) {
                $result->settle(true, $value);
            }
        };
        foreach ($promises as $key => $promise) {
            $promise->observe(
                $onFulfilled,
                static function (Throwable $reason) use ($key, &$reasons, &$remaining, $result, $promises): void {
                    if ($result->state !== self::PENDING) {
                        return;
                    }
                    $reasons[$key] = $reason;
                    if (--$remaining === 0) {
                        $ordered = [];
                        foreach ($promises as $k => $_) {
                            $ordered[$k] = $reasons[$k];
                        }
                        $result->settle(false, new AggregateException($ordered));
                    }
                },
            );
            if ($result->state !== self::PENDING) {
                break;
            }
        }
        return $result;
    }

    /**
     * @internal Settle through a Deferred instead.
     */
    public function settle(bool $fulfilled, mixed $result): void
    {
        if ($this->state !== self::PENDING) {
            if ($this->state === self::REJECTED && $this->result instanceof CancelledException) {
                return;
            }
            throw new LogicException('Promise is already settled');
        }
        if ($fulfilled && $result instanceof self) {
            throw new InvalidArgumentException('Cannot resolve a promise with another promise');
        }
        $this->state = $fulfilled ? self::FULFILLED : self::REJECTED;
        $this->result = $result;
        if ($this->handlers === []) {
            $this->handlers = null;
            if (!$fulfilled) {
                $this->track();
            }
            return;
        }

        // Derived promises settled along the way are queued and run after this one, which keeps long chains off
        // the call stack. Handlers registered while these run land in the fresh array and get picked up next pass.
        $current = $this;
        $queue = [];
        $error = null;
        for ($q = 0; ; ++$q) {
            $handled = false;
            $handlers = $current->handlers ?? [];
            while ($handlers !== []) {
                $current->handlers = [];
                for ($i = 0, $n = count($handlers); $i < $n; $i += 3) {
                    $child = $handlers[$i + 2];
                    if ($child instanceof self) {
                        $handled = true;
                        if (self::invoke($fulfilled, $result, $handlers[$i], $handlers[$i + 1], $child)) {
                            $queue[] = $child;
                        }
                        continue;
                    }
                    $callback = $handlers[$fulfilled ? $i : $i + 1];
                    if (!$callback instanceof Closure) {
                        continue;
                    }
                    $handled = true;
                    try {
                        $callback($result);
                    } catch (Throwable $e) {
                        if ($error === null) {
                            $error = $e;
                        } else {
                            UnhandledRejection::report($e);
                        }
                    }
                }
                $handlers = $current->handlers ?? [];
            }
            $current->handlers = null;
            if (!$fulfilled && !$handled) {
                $current->track();
            }
            if (!isset($queue[$q])) {
                break;
            }
            $current = $queue[$q];
            unset($queue[$q]);
            $fulfilled = $current->state === self::FULFILLED;
            $result = $current->result;
        }
        if ($error !== null) {
            throw $error;
        }
    }

    private function __clone()
    {
    }

    private function track(): void
    {
        if ($this->result instanceof Throwable && !$this->result instanceof CancelledException) {
            $this->unhandled = new UnhandledRejection($this->result);
        }
    }

    private function handle(): void
    {
        if ($this->unhandled !== null) {
            $this->unhandled->reason = null;
            $this->unhandled = null;
        }
    }

    /**
     * Settles the child of a then() from its parent's outcome. Returns whether the child settled right away and
     * needs its handlers run, as opposed to following a promise that is still pending.
     *
     * @param Closure|Promise<mixed>|null $onFulfilled
     * @param Closure|Promise<mixed>|null $onRejected
     * @param Promise<mixed> $child
     */
    private static function invoke(bool $fulfilled, mixed $result, Closure|Promise|null $onFulfilled, Closure|Promise|null $onRejected, Promise $child): bool
    {
        $callback = $fulfilled ? $onFulfilled : $onRejected;
        if ($callback instanceof Closure) {
            try {
                $result = $callback($result);
                $fulfilled = true;
            } catch (Throwable $e) {
                $result = $e;
                $fulfilled = false;
            }
            if ($fulfilled && $result instanceof self) {
                return $child->adopt($result);
            }
        }
        $child->state = $fulfilled ? self::FULFILLED : self::REJECTED;
        $child->result = $result;
        return true;
    }

    /**
     * @param Promise<mixed> $target
     */
    private function adopt(Promise $target): bool
    {
        // follow the chain to its end so later adopters link there directly and chains stay short
        $root = $target;
        while ($root !== $this) {
            $next = $root->result;
            if ($root->state === self::PENDING && $next instanceof self) {
                $root = $next;
                continue;
            }
            if ($root->handlers === null) {
                $root->handle();
                $this->state = $root->state;
                $this->result = $root->result;
                return true;
            }
            if ($root->state === self::PENDING) {
                $this->result = $root;
            }
            $root->handlers[] = null;
            $root->handlers[] = null;
            $root->handlers[] = $this;
            return false;
        }
        $this->state = self::REJECTED;
        $this->result = new LogicException('Circular promise resolution');
        return true;
    }

    /**
     * Advances an async() fiber and settles its promise once the function returns or throws.
     *
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     * @param Closure(): mixed $advance
     */
    private static function step(Fiber $fiber, Closure $advance): void
    {
        /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, Promise<mixed>> $fibers */
        $fibers = self::$fibers;
        $promise = $fibers[$fiber];
        try {
            $advance();
            if (!$fiber->isTerminated()) {
                return;
            }
            $value = $fiber->getReturn();
        } catch (Throwable $e) {
            unset($fibers[$fiber]);
            $promise->settle(false, $e);
            return;
        }
        unset($fibers[$fiber]);
        if ($value === $promise) {
            $promise->settle(false, new LogicException('Circular promise resolution'));
        } elseif ($value instanceof self) {
            $value->observe(
                static function (mixed $value) use ($promise): void { $promise->settle(true, $value); },
                static function (Throwable $reason) use ($promise): void { $promise->settle(false, $reason); },
            );
        } else {
            $promise->settle(true, $value);
        }
    }
}
