<?php

declare(strict_types=1);

namespace Vow\Tests;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use Vow\CancelledException;
use Vow\Deferred;
use Vow\Promise;

final class SettlementTest extends TestCase
{
    public function testResolveRunsHandlersSynchronouslyInOrder(): void
    {
        /** @var Deferred<int> $deferred */
        $deferred = new Deferred();
        $log = [];
        $deferred->promise->observe(function (int $v) use (&$log): void { $log[] = "a$v"; });
        $deferred->promise->observe(function (int $v) use (&$log): void { $log[] = "b$v"; });
        self::assertSame([], $log);

        $deferred->resolve(1);
        self::assertSame(['a1', 'b1'], $log);
        self::assertTrue($deferred->promise->isFulfilled());
        self::assertSame(1, $deferred->promise->value());
    }

    public function testRejectRunsRejectionHandlers(): void
    {
        $deferred = new Deferred();
        $reason = new RuntimeException('nope');
        $seen = null;
        $deferred->promise->observe(fn() => self::fail('fulfilled'), function (Throwable $e) use (&$seen): void { $seen = $e; });

        $deferred->reject($reason);
        self::assertSame($reason, $seen);
        self::assertTrue($deferred->promise->isRejected());
        self::assertSame($reason, $deferred->promise->reason());
    }

    public function testHandlersRegisteredAfterSettlementRunImmediately(): void
    {
        /** @var Deferred<string> $deferred */
        $deferred = new Deferred();
        $deferred->resolve('x');
        $seen = null;
        $deferred->promise->observe(function (string $v) use (&$seen): void { $seen = $v; });
        self::assertSame('x', $seen);

        $rejected = Promise::rejected(new RuntimeException('r'));
        $seen = null;
        $rejected->observe(null, function (Throwable $e) use (&$seen): void { $seen = $e->getMessage(); });
        self::assertSame('r', $seen);
    }

    public function testSettlingWithoutHandlers(): void
    {
        $deferred = new Deferred();
        $deferred->resolve(null);
        self::assertTrue($deferred->promise->isFulfilled());
        self::assertNull($deferred->promise->value());

        $deferred = new Deferred();
        $deferred->reject(new RuntimeException());
        self::assertTrue($deferred->promise->isRejected());
        $deferred->promise->reason();
    }

    public function testRepeatedSettlementThrows(): void
    {
        $deferred = new Deferred();
        $deferred->resolve(1);
        try {
            $deferred->resolve(2);
            self::fail('second resolve');
        } catch (LogicException) {
        }
        try {
            $deferred->reject(new RuntimeException());
            self::fail('reject after resolve');
        } catch (LogicException) {
        }
        self::assertSame(1, $deferred->promise->value());
    }

    public function testResolvingWithAPromiseIsRejected(): void
    {
        $deferred = new Deferred();
        $this->expectException(InvalidArgumentException::class);
        $deferred->resolve(Promise::resolved(1));
    }

    public function testSelfResolutionIsRejectedWithoutSettling(): void
    {
        /** @var Deferred<mixed> $deferred */
        $deferred = new Deferred();
        try {
            $deferred->resolve($deferred->promise);
            self::fail('self resolution');
        } catch (InvalidArgumentException) {
        }
        self::assertTrue($deferred->promise->isPending());
    }

    public function testNullAndFalsyValuesAreRealValues(): void
    {
        foreach ([null, false, 0, '', []] as $value) {
            $deferred = new Deferred();
            $seen = 'unset';
            $deferred->promise->observe(function (mixed $v) use (&$seen): void { $seen = $v; });
            $deferred->resolve($value);
            self::assertSame($value, $seen);
            self::assertSame($value, $deferred->promise->value());
        }
    }

    public function testValueOnPendingThrows(): void
    {
        $this->expectException(LogicException::class);
        (new Deferred())->promise->value();
    }

    public function testValueRethrowsReason(): void
    {
        $reason = new RuntimeException('boom');
        $this->expectExceptionObject($reason);
        Promise::rejected($reason)->value();
    }

    public function testSameClosureCanBeRegisteredTwice(): void
    {
        $deferred = new Deferred();
        $count = 0;
        $handler = function () use (&$count): void { ++$count; };
        $deferred->promise->observe($handler);
        $deferred->promise->observe($handler);
        $deferred->resolve(1);
        self::assertSame(2, $count);
    }

    public function testHandlerExceptionDoesNotStopOtherHandlers(): void
    {
        $deferred = new Deferred();
        $log = [];
        $first = new RuntimeException('first');
        $second = new RuntimeException('second');
        $deferred->promise->observe(function () use ($first): never { throw $first; });
        $deferred->promise->observe(function () use (&$log): void { $log[] = 'b'; });
        $deferred->promise->observe(function () use ($second): never { throw $second; });
        $deferred->promise->observe(function () use (&$log): void { $log[] = 'd'; });

        try {
            $deferred->resolve(1);
            self::fail('no rethrow');
        } catch (RuntimeException $e) {
            self::assertSame($first, $e);
        }
        self::assertSame(['b', 'd'], $log);
        self::assertSame([$second], $this->unhandled);
        self::assertTrue($deferred->promise->isFulfilled());

        $late = false;
        $deferred->promise->observe(function () use (&$late): void { $late = true; });
        self::assertTrue($late);
    }

    public function testHandlersRegisteredDuringSettlementRunAfterExistingOnes(): void
    {
        $deferred = new Deferred();
        $log = [];
        $promise = $deferred->promise;
        $promise->observe(function () use ($promise, &$log): void {
            $log[] = 'a';
            $promise->observe(function () use (&$log): void { $log[] = 'late'; });
            $log[] = 'a-end';
        });
        $promise->observe(function () use (&$log): void { $log[] = 'b'; });
        $deferred->resolve(1);
        self::assertSame(['a', 'a-end', 'b', 'late'], $log);

        $promise->observe(function () use (&$log): void { $log[] = 'after'; });
        self::assertSame('after', $log[4]);
    }

    public function testReentrantSettlementCompletesBeforeReturning(): void
    {
        $outer = new Deferred();
        $inner = new Deferred();
        $log = [];
        $inner->promise->observe(function () use (&$log): void { $log[] = 'inner'; });
        $outer->promise->observe(function () use ($inner, &$log): void {
            $inner->resolve(1);
            $log[] = 'after inner resolve';
        });
        $outer->resolve(1);
        self::assertSame(['inner', 'after inner resolve'], $log);
    }

    public function testSettlingTheSamePromiseFromItsOwnHandlerThrows(): void
    {
        $deferred = new Deferred();
        $caught = null;
        $deferred->promise->observe(function () use ($deferred, &$caught): void {
            try {
                $deferred->resolve(2);
            } catch (LogicException $e) {
                $caught = $e;
            }
        });
        $deferred->resolve(1);
        self::assertInstanceOf(LogicException::class, $caught);
        self::assertSame(1, $deferred->promise->value());
    }

    public function testCancelBeforeSettlement(): void
    {
        $deferred = new Deferred();
        $seen = null;
        $deferred->promise->observe(fn() => self::fail('fulfilled'), function (Throwable $e) use (&$seen): void { $seen = $e; });
        $deferred->cancel();
        self::assertInstanceOf(CancelledException::class, $seen);

        $deferred->resolve('late worker result');
        $deferred->reject(new RuntimeException('late failure'));
        $deferred->cancel();
        self::assertInstanceOf(CancelledException::class, $deferred->promise->reason());
    }

    public function testCancelAfterSettlementIsANoOp(): void
    {
        $deferred = new Deferred();
        $deferred->resolve(1);
        $deferred->cancel();
        self::assertSame(1, $deferred->promise->value());
    }

    public function testCancelWithCustomReason(): void
    {
        $deferred = new Deferred();
        $reason = new CancelledException('Timed out');
        $deferred->cancel($reason);
        self::assertSame($reason, $deferred->promise->reason());
    }

    public function testCancelledPromisesAreNeverReportedAsUnhandled(): void
    {
        $deferred = new Deferred();
        $deferred->cancel();
        unset($deferred);
        Promise::rejected(new CancelledException());
        self::assertSame([], $this->unhandled);
    }

    public function testClonesAreForbidden(): void
    {
        $this->expectException(\Error::class);
        $promise = Promise::resolved(1);
        $copy = clone $promise;
        self::assertNotSame($promise, $copy);
    }
}
