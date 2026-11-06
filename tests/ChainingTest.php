<?php

declare(strict_types=1);

namespace Vow\Tests;

use LogicException;
use RuntimeException;
use Throwable;
use Vow\Deferred;
use Vow\Promise;

final class ChainingTest extends TestCase
{
    public function testThenTransformsValue(): void
    {
        /** @var Deferred<int> $deferred */
        $deferred = new Deferred();
        $chained = $deferred->promise->then(fn(int $v): int => $v * 2)->then(fn(int $v): string => "v$v");
        self::assertTrue($chained->isPending());
        $deferred->resolve(21);
        self::assertSame('v42', $chained->value());
    }

    public function testThenOnSettledPromiseRunsImmediately(): void
    {
        $promise = Promise::resolved(2)->then(fn(int $v): int => $v + 1);
        self::assertSame(3, $promise->value());
    }

    public function testMissingCallbacksPassOutcomesThrough(): void
    {
        self::assertSame(5, Promise::resolved(5)->then()->catch(fn(): int => 0)->value());

        $reason = new RuntimeException();
        $promise = Promise::rejected($reason)->then(fn(): int => 1);
        self::assertSame($reason, $promise->reason());
    }

    public function testThrowingCallbackRejectsChild(): void
    {
        $reason = new RuntimeException('bad');
        $promise = Promise::resolved(1)->then(function () use ($reason): never { throw $reason; });
        self::assertSame($reason, $promise->reason());
    }

    public function testCatchRecovers(): void
    {
        $deferred = new Deferred();
        $promise = $deferred->promise
            ->then(fn(): never => throw new RuntimeException('first'))
            ->then(fn(): string => 'skipped')
            ->catch(fn(Throwable $e): string => 'recovered from ' . $e->getMessage());
        $deferred->resolve(1);
        self::assertSame('recovered from first', $promise->value());
    }

    public function testCatchCanRethrow(): void
    {
        $other = new LogicException('other');
        $promise = Promise::rejected(new RuntimeException())->catch(function () use ($other): never { throw $other; });
        self::assertSame($other, $promise->reason());
    }

    public function testFinallyPassesOutcomeThrough(): void
    {
        $calls = 0;
        $fulfilled = Promise::resolved('v')->finally(function () use (&$calls): string { ++$calls; return 'ignored'; });
        self::assertSame('v', $fulfilled->value());

        $reason = new RuntimeException();
        $rejected = Promise::rejected($reason)->finally(function () use (&$calls): void { ++$calls; });
        self::assertSame($reason, $rejected->reason());
        self::assertSame(2, $calls);
    }

    public function testFinallyThrowingOverridesOutcome(): void
    {
        $reason = new RuntimeException('finally');
        $promise = Promise::resolved(1)->finally(function () use ($reason): never { throw $reason; });
        self::assertSame($reason, $promise->reason());
    }

    public function testFinallyWaitsForReturnedPromise(): void
    {
        $gate = new Deferred();
        $promise = Promise::resolved('v')->finally(fn(): Promise => $gate->promise);
        self::assertTrue($promise->isPending());
        $gate->resolve(null);
        self::assertSame('v', $promise->value());

        $gate = new Deferred();
        $original = new RuntimeException('original');
        $promise = Promise::rejected($original)->finally(fn(): Promise => $gate->promise);
        $gate->resolve(null);
        self::assertSame($original, $promise->reason());

        $gate = new Deferred();
        $failure = new RuntimeException('gate');
        $promise = Promise::resolved('v')->finally(fn(): Promise => $gate->promise);
        $gate->reject($failure);
        self::assertSame($failure, $promise->reason());
    }

    public function testCallbackReturningPendingPromiseIsAdopted(): void
    {
        $first = new Deferred();
        $second = new Deferred();
        $promise = $first->promise->then(fn(): Promise => $second->promise);
        $first->resolve(1);
        self::assertTrue($promise->isPending());
        $second->resolve('adopted');
        self::assertSame('adopted', $promise->value());
    }

    public function testCallbackReturningSettledPromiseIsAdopted(): void
    {
        self::assertSame('x', Promise::resolved(1)->then(fn(): Promise => Promise::resolved('x'))->value());

        $reason = new RuntimeException();
        $promise = Promise::resolved(1)->then(fn(): Promise => Promise::rejected($reason));
        self::assertSame($reason, $promise->reason());
    }

    public function testAdoptedRejectionIsReportedOnceAtTheEnd(): void
    {
        $inner = new Deferred();
        $outer = Promise::resolved(1)->then(fn(): Promise => $inner->promise);
        $inner->reject(new RuntimeException('leaf'));
        self::assertSame([], $this->unhandled);
        unset($outer);
        self::assertCount(1, $this->unhandled);
        unset($inner);
        self::assertCount(1, $this->unhandled);
    }

    public function testNestedPromiseChainsFlatten(): void
    {
        /** @var Deferred<int> $deferred */
        $deferred = new Deferred();
        $promise = $deferred->promise->then(
            fn(int $v): Promise => Promise::resolved($v + 1)->then(
                fn(int $w): Promise => Promise::resolved($w * 10),
            ),
        );
        $deferred->resolve(1);
        self::assertSame(20, $promise->value());
    }

    public function testSelfAdoptionRejects(): void
    {
        $deferred = new Deferred();
        $promise = null;
        $promise = $deferred->promise->then(function () use (&$promise): ?Promise { return $promise; });
        $deferred->resolve(1);
        self::assertInstanceOf(LogicException::class, $promise->reason());
    }

    public function testCircularAdoptionRejects(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $first = null;
        $second = null;
        $first = $a->promise->then(function () use (&$second): ?Promise { return $second; });
        $second = $b->promise->then(function () use (&$first): Promise { return $first; });
        $a->resolve(1);
        self::assertTrue($first->isPending());
        $b->resolve(1);
        self::assertInstanceOf(LogicException::class, $second->reason());
        self::assertInstanceOf(LogicException::class, $first->reason());
    }

    public function testChildrenSettleAfterParentHandlers(): void
    {
        $deferred = new Deferred();
        $log = [];
        $deferred->promise->then(function () use (&$log): void { $log[] = 'child callback'; })
            ->observe(function () use (&$log): void { $log[] = 'grandchild observer'; });
        $deferred->promise->observe(function () use (&$log): void { $log[] = 'sibling observer'; });
        $deferred->resolve(1);
        self::assertSame(['child callback', 'sibling observer', 'grandchild observer'], $log);
    }

    public function testLongPendingChainDoesNotExhaustTheStack(): void
    {
        /** @var Deferred<int> $deferred */
        $deferred = new Deferred();
        $promise = $deferred->promise;
        $increment = static fn(int $v): int => $v + 1;
        for ($i = 0; $i < 100_000; ++$i) {
            $promise = $promise->then($increment);
        }
        $deferred->resolve(0);
        self::assertSame(100_000, $promise->value());
    }

    public function testLongAdoptionChainDoesNotExhaustTheStack(): void
    {
        $root = new Deferred();
        $promise = $root->promise;
        for ($i = 0; $i < 100_000; ++$i) {
            $inner = $promise;
            $promise = Promise::resolved(null)->then(fn(): Promise => $inner);
        }
        $root->resolve('deep');
        self::assertSame('deep', $promise->value());
    }

    public function testLongRejectionChainDoesNotExhaustTheStack(): void
    {
        $deferred = new Deferred();
        $promise = $deferred->promise;
        $callback = static fn(): int => 1;
        for ($i = 0; $i < 100_000; ++$i) {
            $promise = $promise->then($callback);
        }
        $deferred->reject(new RuntimeException('deep'));
        self::assertSame('deep', $promise->reason()?->getMessage());
    }
}
