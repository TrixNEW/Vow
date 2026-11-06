<?php

declare(strict_types=1);

namespace Vow\Tests;

use LogicException;
use RuntimeException;
use Vow\Deferred;
use Vow\Promise;

final class AsyncTest extends TestCase
{
    public function testAsyncWithoutAwaitRunsImmediately(): void
    {
        $promise = Promise::async(fn(): int => 2 + 3);
        self::assertSame(5, $promise->value());
    }

    public function testAwaitSuspendsUntilSettled(): void
    {
        /** @var Deferred<string> $first */
        $first = new Deferred();
        /** @var Deferred<string> $second */
        $second = new Deferred();
        $log = [];
        $promise = Promise::async(function () use ($first, $second, &$log): string {
            $log[] = 'start';
            $a = $first->promise->await();
            $log[] = "got $a";
            $b = $second->promise->await();
            return "$a$b";
        });
        self::assertSame(['start'], $log);
        self::assertTrue($promise->isPending());

        $first->resolve('x');
        self::assertSame(['start', 'got x'], $log);
        $second->resolve('y');
        self::assertSame('xy', $promise->value());
    }

    public function testAwaitThrowsRejectionIntoFiber(): void
    {
        $deferred = new Deferred();
        $promise = Promise::async(function () use ($deferred): string {
            try {
                $deferred->promise->await();
            } catch (RuntimeException $e) {
                return 'caught ' . $e->getMessage();
            }
            return 'not reached';
        });
        $deferred->reject(new RuntimeException('boom'));
        self::assertSame('caught boom', $promise->value());
    }

    public function testThrowingAsyncFunctionRejects(): void
    {
        $deferred = new Deferred();
        $promise = Promise::async(function () use ($deferred): never {
            $deferred->promise->await();
            throw new RuntimeException('after await');
        });
        $deferred->resolve(1);
        self::assertSame('after await', $promise->reason()?->getMessage());
    }

    public function testAwaitSettledPromiseOutsideFiber(): void
    {
        self::assertSame(1, Promise::resolved(1)->await());
    }

    public function testAwaitPendingOutsideAsyncThrows(): void
    {
        $this->expectException(LogicException::class);
        (new Deferred())->promise->await();
    }

    public function testAwaitPendingInForeignFiberThrows(): void
    {
        $deferred = new Deferred();
        $fiber = new \Fiber(fn(): mixed => $deferred->promise->await());
        $this->expectException(LogicException::class);
        $fiber->start();
    }

    public function testAsyncReturningPromiseIsAdopted(): void
    {
        $deferred = new Deferred();
        $promise = Promise::async(fn(): Promise => $deferred->promise);
        $deferred->resolve('later');
        self::assertSame('later', $promise->value());
    }

    public function testHandlersOfAsyncResultRunOutsideTheFiber(): void
    {
        $deferred = new Deferred();
        $inside = null;
        Promise::async(fn(): mixed => $deferred->promise->await())
            ->observe(function () use (&$inside): void { $inside = \Fiber::getCurrent(); });
        $deferred->resolve(1);
        self::assertNull($inside);
    }
}
