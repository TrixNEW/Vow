# Vow

A lightweight, strongly typed promise library for PHP 8.4. Built for performance, with no dependencies or event loop required.

```bash
composer require trix/vow
```

## Quick start

Create a `Deferred` to resolve or reject a promise, and use `observe()` to handle the result.

```php
use Vow\Deferred;

$deferred = new Deferred();

$deferred->promise->observe(
    fn (string $body) => print "got $body\n",
    fn (Throwable $e) => print "failed: {$e->getMessage()}\n",
);

$deferred->resolve('hello');
// or $deferred->reject(new RuntimeException('boom'));
```

Keep the `Deferred` where the operation is handled and pass its promise to consumers. Promises are read-only, so they can't be resolved or rejected from the outside.

## API

### Chaining

Use `then()`, `catch()`, and `finally()` to chain operations. If a callback returns another promise, Vow automatically adopts its result.

```php
$user = fetchUser($id)
    ->then(fn (array $row) => fetchProfile($row['id']))
    ->then(fn (array $profile) => $profile['name'])
    ->catch(fn (Throwable $e) => 'anonymous')
    ->finally(fn () => $log->debug('done'));
```

### Inspecting promises

```php
$promise->isPending();
$promise->isFulfilled();
$promise->isRejected();

$promise->value();  // Returns the value or throws
$promise->reason(); // Returns the rejection reason or null
```

Calling `value()` on a pending promise throws a `LogicException`. Rejected promises throw their rejection reason.

### Creating settled promises

```php
use Vow\Promise;

$ok = Promise::resolved(42);
$no = Promise::rejected(new RuntimeException('nope'));
```

### Combinators

Vow includes the usual promise combinators, with array keys preserved.

```php
Promise::all(['a' => $a, 'b' => $b]); // All values, or the first rejection
Promise::allSettled([$a, $b]);        // Wait for every result
Promise::race([$a, $b]);              // First to settle
Promise::any([$a, $b]);               // First to fulfill
```

If every promise passed to `any()` rejects, the result is an `AggregateException` containing the individual reasons in `$reasons`.

### Cancellation

Cancelled promises ignore late results, making it safe for an operation to finish after a timeout.

```php
$deferred = new Deferred();

$deferred->cancel();        // Rejects with CancelledException
$deferred->resolve('late'); // Ignored
```

### Unhandled rejections

Unhandled rejections are written to `error_log()` by default. You can provide your own handler:

```php
Promise::setRejectionHandler(
    fn (Throwable $e) => $logger->error(
        $e->getMessage(),
        ['exception' => $e],
    ),
);
```

### Async / await

Vow supports PHP fibers through `Promise::async()` and `await()`, allowing asynchronous-looking code without callbacks at every step.

```php
$result = Promise::async(function () use ($client) {
    $user = $client->fetchUser(1)->await();
    $posts = $client->fetchPosts($user['id'])->await();

    return count($posts);
});
```

`async()` runs the function in a fiber, while `await()` suspends it until the promise settles.

Calling `await()` on a pending promise outside `Promise::async()` throws a `LogicException`.

## Behavior

Vow keeps promise execution predictable:

- **Synchronous callbacks.** Handlers run immediately when a promise settles, or when attached to an already settled promise.
- **Consistent ordering.** Callbacks execute in the order they were registered.
- **Stack-safe chains.** Derived promises settle after their parent's callbacks, avoiding stack growth in long chains.
- **Single settlement.** Promises can only settle once. Repeated calls throw a `LogicException`, except after cancellation.
- **Callback isolation.** If an `observe()` callback throws, the remaining callbacks still run. The first exception is then rethrown.
- **Unhandled rejections.** Rejections are reported when the promise is released. Cancellations are excluded.
- **Thread ownership.** Promises aren't thread-safe and must be settled on their owning thread.

## Benchmarks

Benchmarked on PHP 8.4 using the median of interleaved runs in fresh processes. Results are measured in nanoseconds per operation (**lower is better**).

### Without JIT

| Scenario | Vow | ReactPHP | Amp |
| --- | --- | --- | --- |
| Create + resolve | 285 | 1,134 | 451 |
| Pending, 1 handler | 709 | 3,282 | 9,227 |
| Settled, 1 handler | 402 | 1,599 | 9,413 |
| Chain of 10 | 6,867 | 24,326 | 36,752 |
| all() of 10 | 10,740 | 42,261 | – |

### With JIT (tracing)

| Scenario | Vow | ReactPHP | Amp |
| --- | --- | --- | --- |
| Create + resolve | 159 | 789 | 317 |
| Pending, 1 handler | 348 | 2,435 | 8,163 |
| Settled, 1 handler | 203 | 1,040 | 8,089 |
| Chain of 10 | 3,976 | 18,442 | 32,007 |
| all() of 10 | 6,099 | 30,607 | – |

### Memory usage

Bytes per pending promise with one handler (**lower is better**).

| Vow | ReactPHP | Amp |
| --- | --- | --- |
| 409 | 4,728 | 3,184 |

Vow performs well across the benchmarks, particularly with JIT enabled, where it leads in every tested scenario.

See [tests/bench/RESULTS.md](tests/bench/RESULTS.md) for the full results and methodology.

To run the benchmarks yourself:

```bash
cd tests/bench
composer install
php run.php
```

## Development

```bash
composer install
composer test
composer analyse
```
