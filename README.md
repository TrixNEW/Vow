# Vow

Fast, synchronous, strongly typed promises for PHP 8.4. No dependencies and no event loop.

Vow was written to replace the promises in [Quark](https://github.com/Bedrock-Phanatics/Quark), a Minecraft server that
settles thousands of promises per tick, but it has nothing Quark specific in it.

```
composer require trix/vow
```

## Usage

A `Deferred` settles a promise and a `Promise` is the read-only side you hand out.

```php
use Vow\Deferred;
use Vow\Promise;

/** @return Promise<Chunk> */
function loadChunk(int $x, int $z): Promise
{
    /** @var Deferred<Chunk> $deferred */
    $deferred = new Deferred();
    $pool->submit(new LoadChunkTask($x, $z, $deferred->resolve(...), $deferred->reject(...)));
    return $deferred->promise;
}

loadChunk(0, 0)->observe(
    fn(Chunk $chunk) => $player->sendChunk($chunk),
    fn(Throwable $e) => $logger->logException($e),
);
```

`observe()` is the cheapest way to consume a promise because it doesn't create another one. Use `then()`, `catch()` and
`finally()` when you need a derived promise:

```php
$world->requestChunk($x, $z)
    ->then(fn(Chunk $chunk) => $world->findSafeSpawn($chunk))   // a returned promise is adopted
    ->catch(fn(Throwable $e) => $world->getSpawnLocation())      // a rejection can be recovered
    ->then(fn(Position $spawn) => $player->teleport($spawn))     // a plain value is passed on
    ->finally(fn() => $loader->release());                      // runs either way, the outcome passes through
```

Settled promises can be read directly, and combinators keep array keys and input order:

```php
$promise->isPending(); $promise->isFulfilled(); $promise->isRejected();
$promise->value();   // the value, or throws the reason (or LogicException while pending)
$promise->reason();  // the Throwable, or null unless rejected

Promise::resolved($value);
Promise::rejected($reason);
Promise::all(['a' => $a, 'b' => $b]);   // fulfils with ['a' => ..., 'b' => ...], rejects on the first rejection
Promise::allSettled($promises);          // fulfils with the same promises once all are settled
Promise::race($promises);                // settles like the first to settle
Promise::any($promises);                 // first fulfilment, or AggregateException with every reason
```

### Fibers

`Promise::async()` runs a function in a fiber where `await()` suspends on pending promises. It needs no event loop, the
fiber resumes inside whatever settles the promise it waits on.

```php
$promise = Promise::async(function () use ($db, $player): int {
    $row = $db->fetch($player->getUuid())->await();
    return $db->save($row->withLogin(time()))->await();
});
```

`await()` on a settled promise works anywhere. On a pending promise it only works inside `Promise::async()`, and throws
a `LogicException` elsewhere instead of blocking.

## How it behaves

**Callbacks are synchronous.** When a promise settles, its callbacks run before `resolve()` or `reject()` returns.
When it is already settled, a new callback runs before `then()` or `observe()` returns. Nothing is ever deferred to a
later tick, and there is no queue to pump.

**Ordering is deterministic.** A promise runs its callbacks in registration order. A callback registered while the
promise is running its callbacks runs after the ones already registered. Promises derived with `then()` settle after
their parent has run all of its callbacks, so a chain of a hundred thousand `then()`s doesn't grow the stack.

**Settlement happens once.** A second `resolve()` or `reject()` throws a `LogicException`. Resolving with a promise
throws an `InvalidArgumentException`, because `then()` callbacks are the place to return promises. A callback
returning the promise it belongs to, or a promise waiting on it, rejects with a `LogicException` rather than hanging.

**Callback exceptions don't break other subscribers.** A `then()` callback that throws rejects its derived promise. An
`observe()` callback that throws doesn't stop the remaining callbacks, and the first exception is rethrown from the
`resolve()` or `reject()` call that settled the promise once they have all run.

**Cancellation belongs to the owner.** `Deferred::cancel()` rejects with a `CancelledException` and makes later
`resolve()` and `reject()` calls no-ops, so a worker that finishes after a cancellation or timeout is harmless. Only the
`Deferred` can cancel, so one consumer can't cancel work that others are still waiting for. Timeouts are a cancel
scheduled with whatever timer you already have:

```php
$timer = $scheduler->scheduleDelayedTask(new ClosureTask(fn() => $deferred->cancel(new CancelledException('Timed out'))), 20 * 30);
$deferred->promise->finally(fn() => $timer->cancel());
```

**Rejections nobody handles are reported.** A rejected promise with no rejection handler is reported once the last
reference to it is gone, which with PHP's reference counting is right away unless it sits in a cycle. Cancellations are
never reported. The default report goes to `error_log()`:

```php
Promise::setRejectionHandler(fn(Throwable $e) => $logger->logException($e));
```

**Promises belong to one thread.** Settle them on the thread that created them, for example from an async task's
completion callback on the main thread, never from inside the worker.

## Performance

Vow allocates two objects per pending promise, and one array once the first callback is registered. Settled promises
drop their callbacks immediately. Compared with Quark's current promises it uses 42% less memory per pending promise.
With the tracing JIT it is faster in every scenario, about 1.5 times for a promise with one handler and 2 times for
chains. Without JIT, creating, chaining and pre-settled promises are still faster, while settling a pending promise is
5 to 12% slower, the cost of isolating callback exceptions from each other. Full numbers against Quark, xSuperr/Promise,
ReactPHP and Amp are in [bench/RESULTS.md](bench/RESULTS.md), and [bench/](bench) has the harness to reproduce them.

## Quark

[docs/quark-migration.md](docs/quark-migration.md) covers replacing `quark\promise` and `CompressBatchPromise`.

## Development

```
composer install
composer test
composer analyse
cd bench && composer install && php run.php
```

## License

MIT
