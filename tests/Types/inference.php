<?php

declare(strict_types=1);

namespace Vow\Tests\Types;

use Throwable;
use Vow\Deferred;
use Vow\Promise;

use function PHPStan\Testing\assertType;

/** @var Deferred<int> $deferred */
$deferred = new Deferred();
$promise = $deferred->promise;

assertType('Vow\Promise<int>', $promise);
assertType('int', $promise->value());
assertType('int', $promise->await());
assertType('Vow\Promise<string>', $promise->then(fn(int $v): string => label($v)));
assertType('Vow\Promise<string>', $promise->then(fn(int $v): Promise => Promise::resolved(label($v))));
assertType('Vow\Promise<int>', $promise->then());
assertType('Vow\Promise<int|null>', $promise->catch(fn(Throwable $e): ?int => null));
assertType('Vow\Promise<bool|string>', $promise->then(fn(): string => 'a', fn(): bool => false));
assertType('Vow\Promise<int>', $promise->finally(fn(): null => null));
assertType('Vow\Promise<never>', Promise::rejected(new \RuntimeException()));
assertType('Vow\Promise<float>', Promise::resolved(1.5));
assertType('Vow\Promise<string>', Promise::async(fn(): string => 'a'));

/** @var array<string, Promise<int>> $map */
$map = [];
assertType('Vow\Promise<array<string, int>>', Promise::all($map));
assertType('Vow\Promise<array<string, Vow\Promise<int>>>', Promise::allSettled($map));
assertType('Vow\Promise<int>', Promise::race($map));
assertType('Vow\Promise<int>', Promise::any($map));

function label(int $value): string
{
    return str_repeat('x', $value);
}
