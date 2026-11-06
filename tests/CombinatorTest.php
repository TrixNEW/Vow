<?php

declare(strict_types=1);

namespace Vow\Tests;

use RuntimeException;
use ValueError;
use Vow\AggregateException;
use Vow\Deferred;
use Vow\Promise;

final class CombinatorTest extends TestCase
{
    public function testAllPreservesKeysAndInputOrder(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $c = new Deferred();
        $all = Promise::all(['x' => $a->promise, 7 => $b->promise, 'z' => $c->promise]);
        $c->resolve('c');
        $a->resolve(null);
        self::assertTrue($all->isPending());
        $b->resolve('b');
        self::assertSame(['x' => null, 7 => 'b', 'z' => 'c'], $all->value());
    }

    public function testAllWithEmptyArray(): void
    {
        /** @var array<string, Promise<int>> $none */
        $none = [];
        self::assertSame([], Promise::all($none)->value());
    }

    public function testAllWithSettledPromises(): void
    {
        self::assertSame([1, 2], Promise::all([Promise::resolved(1), Promise::resolved(2)])->value());
    }

    public function testAllRejectsWithFirstReasonOnce(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $all = Promise::all([$a->promise, $b->promise]);
        $first = new RuntimeException('first');
        $a->reject($first);
        $b->reject(new RuntimeException('second'));
        self::assertSame($first, $all->reason());
        self::assertSame([], $this->unhandled);
    }

    public function testAllStopsSubscribingAfterEarlyRejection(): void
    {
        $pending = new Deferred();
        $reason = new RuntimeException();
        $all = Promise::all([Promise::rejected($reason), $pending->promise]);
        self::assertSame($reason, $all->reason());
        $pending->resolve(1);
    }

    public function testAllSettledReturnsTheSettledPromises(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $input = ['ok' => $a->promise, 'bad' => $b->promise];
        $settled = Promise::allSettled($input);
        $b->reject(new RuntimeException('bad'));
        self::assertTrue($settled->isPending());
        $a->resolve('fine');

        $result = $settled->value();
        self::assertSame($input, $result);
        self::assertSame('fine', $result['ok']->value());
        self::assertSame('bad', $result['bad']->reason()?->getMessage());
        self::assertSame([], Promise::allSettled([])->value());
    }

    public function testRaceSettlesWithFirst(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $race = Promise::race([$a->promise, $b->promise]);
        $b->resolve('b');
        $a->resolve('a');
        self::assertSame('b', $race->value());

        $a = new Deferred();
        $reason = new RuntimeException();
        $race = Promise::race([$a->promise, Promise::rejected($reason)]);
        self::assertSame($reason, $race->reason());
    }

    public function testRaceWithEmptyArrayThrows(): void
    {
        /** @var list<Promise<int>> $none */
        $none = [];
        $this->expectException(ValueError::class);
        Promise::race($none);
    }

    public function testAnyFulfilsWithFirstFulfilment(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $any = Promise::any([$a->promise, $b->promise]);
        $a->reject(new RuntimeException());
        self::assertTrue($any->isPending());
        $b->resolve('b');
        self::assertSame('b', $any->value());
    }

    public function testAnyRejectsWithAllReasonsInInputOrder(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $any = Promise::any(['a' => $a->promise, 'b' => $b->promise]);
        $second = new RuntimeException('b');
        $first = new RuntimeException('a');
        $b->reject($second);
        $a->reject($first);
        $reason = $any->reason();
        self::assertInstanceOf(AggregateException::class, $reason);
        self::assertSame(['a' => $first, 'b' => $second], $reason->reasons);
    }

    public function testAnyWithEmptyArrayRejects(): void
    {
        /** @var list<Promise<int>> $none */
        $none = [];
        $reason = Promise::any($none)->reason();
        self::assertInstanceOf(AggregateException::class, $reason);
        self::assertSame([], $reason->reasons);
    }

    public function testCombinatorsAcceptMixedStates(): void
    {
        $pending = new Deferred();
        $all = Promise::allSettled([Promise::resolved(1), Promise::rejected(new RuntimeException()), $pending->promise]);
        $pending->resolve(3);
        self::assertCount(3, $all->value());
    }
}
