<?php

declare(strict_types=1);

namespace Vow\Tests;

use RuntimeException;
use Throwable;
use Vow\CancelledException;
use Vow\Deferred;
use Vow\Promise;

final class LifecycleTest extends TestCase
{
    public function testUnhandledRejectionIsReportedWhenReleased(): void
    {
        $reason = new RuntimeException('lost');
        $deferred = new Deferred();
        $deferred->reject($reason);
        self::assertSame([], $this->unhandled);
        unset($deferred);
        self::assertSame([$reason], $this->unhandled);
    }

    public function testRejectionHandledAfterSettlementIsNotReported(): void
    {
        $promise = Promise::rejected(new RuntimeException());
        $promise->catch(fn(): null => null);
        unset($promise);

        $promise = Promise::rejected(new RuntimeException());
        $promise->observe(null, fn() => null);
        unset($promise);

        $promise = Promise::rejected(new RuntimeException());
        $promise->reason();
        unset($promise);

        self::assertSame([], $this->unhandled);
    }

    public function testFulfilmentOnlyObserverDoesNotHandleRejection(): void
    {
        $deferred = new Deferred();
        $deferred->promise->observe(fn() => null);
        $deferred->reject(new RuntimeException());
        unset($deferred);
        self::assertCount(1, $this->unhandled);
    }

    public function testChainReportsOnlyTheLeaf(): void
    {
        $deferred = new Deferred();
        $leaf = $deferred->promise->then(fn(): int => 1)->then(fn(): int => 2);
        $deferred->reject(new RuntimeException('once'));
        unset($deferred, $leaf);
        self::assertCount(1, $this->unhandled);
    }

    public function testReadingQueuedRejectionHandlesIt(): void
    {
        $deferred = new Deferred();
        $reason = new RuntimeException();
        $child = $deferred->promise->then(fn(): never => throw $reason);
        $deferred->promise->observe(function () use ($child, $reason): void {
            self::assertSame($reason, $child->reason());
        });
        $deferred->resolve(null);
        unset($deferred, $child);
        self::assertSame([], $this->unhandled);
    }

    public function testThrowingCallbackWithoutCatchIsReported(): void
    {
        Promise::resolved(1)->then(fn(): never => throw new RuntimeException('bug'));
        self::assertSame('bug', $this->unhandled[0]->getMessage());
    }

    public function testDefaultReporterAndRestoringHandler(): void
    {
        $previous = Promise::setRejectionHandler(null);
        self::assertNotNull($previous);
        self::assertNull(Promise::setRejectionHandler($previous));
    }

    public function testThrowingRejectionHandlerIsContained(): void
    {
        Promise::setRejectionHandler(function (Throwable $reason): never { throw new RuntimeException('handler'); });
        $log = ini_set('error_log', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            Promise::rejected(new RuntimeException());
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
        $this->addToAssertionCount(1);
    }

    public function testSettledPromisesReleaseCallbacks(): void
    {
        $deferred = new Deferred();
        $payload = new \stdClass();
        $ref = \WeakReference::create($payload);
        $deferred->promise->observe(function () use ($payload): void { $payload->seen = true; });
        $deferred->promise->then(function () use ($payload): void { $payload->seen = true; });
        unset($payload);
        self::assertNotNull($ref->get());
        $deferred->resolve(1);
        self::assertNull($ref->get());
    }

    public function testCombinatorsReleaseInputsOnceSettled(): void
    {
        $deferred = new Deferred();
        $promise = $deferred->promise;
        $all = Promise::all([$promise]);
        $ref = \WeakReference::create($all);
        $deferred->resolve(1);
        unset($all);
        self::assertNull($ref->get());
    }

    public function testDroppedPendingPromisesAreCollected(): void
    {
        $deferred = new Deferred();
        $ref = \WeakReference::create($deferred->promise);
        $deferred->promise->then(fn(): int => 1)->then(fn(): int => 2);
        $other = new Deferred();
        $deferred->promise->then(fn(): Promise => $other->promise);
        unset($deferred);
        gc_collect_cycles();
        self::assertNull($ref->get());
    }

    public function testShortCircuitCombinatorsReleaseResultsWithPendingInputs(): void
    {
        foreach (['all' => Promise::all(...), 'race' => Promise::race(...), 'any' => Promise::any(...)] as $method => $combine) {
            $pending = new Deferred();
            $winner = new Deferred();
            $result = $combine([$pending->promise, $winner->promise]);
            $ref = \WeakReference::create($result);
            if ($method === 'all') {
                $winner->reject(new RuntimeException());
                $result->reason();
            } else {
                $winner->resolve(null);
            }
            unset($result);
            self::assertNull($ref->get(), $method);
            $pending->resolve(null);
        }
    }

    public function testFailedAllReleasesValuesWithPendingInputs(): void
    {
        $payload = new \stdClass();
        $ref = \WeakReference::create($payload);
        $pending = new Deferred();
        $failure = new Deferred();
        $all = Promise::all([Promise::resolved($payload), $failure->promise, $pending->promise]);
        unset($payload);
        $failure->reject(new RuntimeException());
        $all->reason();
        self::assertNull($ref->get());
        $pending->resolve(null);
    }

    public function testSuccessfulAnyReleasesRejectionsWithPendingInputs(): void
    {
        $reason = new RuntimeException();
        $ref = \WeakReference::create($reason);
        $pending = new Deferred();
        $winner = new Deferred();
        $any = Promise::any([Promise::rejected($reason), $pending->promise, $winner->promise]);
        unset($reason);
        $error = new RuntimeException('observer');
        $any->observe(fn(): never => throw $error);
        try {
            $winner->resolve(null);
            self::fail('observer exception');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertNull($ref->get());
        self::assertNull($any->value());
        $pending->resolve(null);
    }

    public function testTimeoutRecipeWithLateWorkerCompletion(): void
    {
        $timers = [];
        $schedule = function (int $ticks, \Closure $task) use (&$timers): object {
            $timer = new class ($task) {
                public bool $cancelled = false;
                public function __construct(public \Closure $task) {}
            };
            $timers[] = $timer;
            return $timer;
        };

        $deferred = new Deferred();
        $timer = $schedule(20, fn() => $deferred->cancel(new CancelledException('Timed out')));
        $deferred->promise->finally(function () use ($timer): void { $timer->cancelled = true; });
        $reason = null;
        $deferred->promise->observe(null, function (Throwable $e) use (&$reason): void { $reason = $e; });

        ($timers[0]->task)();
        self::assertSame('Timed out', $reason?->getMessage());
        self::assertTrue($timer->cancelled);

        $deferred->resolve('late worker result');
        self::assertInstanceOf(CancelledException::class, $deferred->promise->reason());
    }

    public function testSustainedWorkloadDoesNotRetainMemory(): void
    {
        $run = static function (): void {
            for ($i = 0; $i < 2_000; ++$i) {
                /** @var Deferred<int> $deferred */
                $deferred = new Deferred();
                $deferred->promise->observe(static fn() => null, static fn() => null);
                $deferred->promise->then(static fn(int $v): int => $v + 1)->catch(static fn(): int => 0);
                if ($i % 3 === 0) {
                    $deferred->reject(new RuntimeException());
                } else {
                    $deferred->resolve($i);
                }
            }
        };
        $run();
        gc_collect_cycles();
        $before = memory_get_usage();
        for ($round = 0; $round < 10; ++$round) {
            $run();
        }
        gc_collect_cycles();
        self::assertLessThan(64 * 1024, memory_get_usage() - $before);
    }
}
