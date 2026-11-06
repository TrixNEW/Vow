# Vow

Synchronous, strongly typed promises for PHP 8.4. No dependencies, no event loop.

```
composer require trix/vow
```

## Quick start

```php
use Vow\Deferred;

$deferred = new Deferred();
$deferred->promise->observe(
    fn (string $body) => print "got $body\n",
    fn (Throwable $e) => print "failed: {$e->getMessage()}\n",
);

$deferred->resolve('hello'); // or $deferred->reject(new RuntimeException('boom'))
```

Keep the `Deferred` with the operation and hand out `$deferred->promise`. The promise is read-only, so consumers cannot settle it.

## API

```php
use Vow\Promise;

// Chaining: a returned promise is adopted
$user = fetchUser($id)
    ->then(fn (array $row) => fetchProfile($row['id']))
    ->then(fn (array $profile) => $profile['name'])
    ->catch(fn (Throwable $e) => 'anonymous')
    ->finally(fn () => $log->debug('done'));

// Inspection
$promise->isPending();
$promise->isFulfilled();
$promise->isRejected();
$promise->value();  // the value, or throws the reason (LogicException while pending)
$promise->reason(); // the Throwable, or null

// Already settled
$ok = Promise::resolved(42);
$no = Promise::rejected(new RuntimeException('nope'));

// Combinators (array keys are preserved)
Promise::all(['a' => $a, 'b' => $b]);  // values by key, rejects on the first rejection
Promise::allSettled([$a, $b]);         // the same promises, once all are settled
Promise::race([$a, $b]);               // settles like the first one to settle
Promise::any([$a, $b]);                // first fulfilment, or AggregateException::$reasons
```

Cancellation turns late results into no-ops, so a slow worker can finish safely after a timeout:

```php
$deferred = new Deferred();
$deferred->cancel();         // rejects with CancelledException
$deferred->resolve('late');  // ignored
```

Unhandled rejections go to `error_log()` by default:

```php
Promise::setRejectionHandler(fn (Throwable $e) => $logger->error($e->getMessage(), ['exception' => $e]));
```

`Promise::async()` runs a function in a fiber, and `await()` suspends it until a pending promise settles:

```php
$result = Promise::async(function () use ($client) {
    $user = $client->fetchUser(1)->await();
    $posts = $client->fetchPosts($user['id'])->await();
    return count($posts);
});
```

`await()` on a pending promise outside `Promise::async()` throws a `LogicException`.

## Guarantees

- Callbacks run synchronously, never on a later tick: before `resolve()` returns, or before `then()` or `observe()` returns if the promise is already settled.
- Callbacks run in registration order.
- Derived promises settle after their parent's callbacks have run, so long chains do not grow the stack.
- A promise settles exactly once. A second `resolve()` or `reject()` throws `LogicException`, except after `cancel()`.
- If an `observe()` callback throws, the others still run and the first exception is rethrown from `resolve()` or `reject()`.
- Unhandled rejections are reported when the promise is released. Cancellations are never reported.
- Promises are not thread-safe. Settle them on the thread that owns them.

## Benchmarks

PHP 8.4, median of interleaved runs in fresh processes. Nanoseconds per operation, lower is better.

JIT off:

| Scenario | Vow | Quark | xSuperr | ReactPHP | Amp |
| --- | --- | --- | --- | --- | --- |
| create + resolve | 285 | 389 | 387 | 1,134 | 451 |
| pending, 1 handler | 709 | 675 | 733 | 3,282 | 9,227 |
| settled, then 1 handler | 402 | 535 | 650 | 1,599 | 9,413 |
| chain of 10 | 6,867 | 9,139 | 9,913 | 24,326 | 36,752 |
| all() of 10 | 10,740 | 11,057 | 11,938 | 42,261 | – |

JIT tracing:

| Scenario | Vow | Quark | xSuperr | ReactPHP | Amp |
| --- | --- | --- | --- | --- | --- |
| create + resolve | 159 | 331 | 334 | 789 | 317 |
| pending, 1 handler | 348 | 531 | 576 | 2,435 | 8,163 |
| settled, then 1 handler | 203 | 446 | 556 | 1,040 | 8,089 |
| chain of 10 | 3,976 | 8,162 | 8,072 | 18,442 | 32,007 |
| all() of 10 | 6,099 | 9,650 | 10,225 | 30,607 | – |

Memory, bytes per pending promise with one handler:

| Vow | Quark | xSuperr | ReactPHP | Amp |
| --- | --- | --- | --- | --- |
| 409 | 705 | 705 | 4,728 | 3,184 |

Without JIT, settling a pending promise is about 5-12% slower than Quark's minimal promises because callback exceptions are isolated. With JIT, Vow is the fastest in every scenario above.

Full results are in [bench/RESULTS.md](bench/RESULTS.md). To reproduce:

```
cd bench && composer install && php run.php
```

## Development

```
composer install
composer test
composer analyse
```

## License

MIT
