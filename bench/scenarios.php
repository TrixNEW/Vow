<?php

declare(strict_types=1);

// Each closure runs the same workload using its library's native API.
// Shared handlers keep closure allocation out of the measurements unless the scenario needs it.

use Amp\DeferredFuture;
use quark\network\mcpe\compression\CompressBatchPromise;
use quark\promise\Promise as QuarkPromise;
use quark\promise\PromiseResolver;
use Revolt\EventLoop;
use Vow\Deferred;
use Vow\Promise;
use xSuperr\Promise\BetterPromiseResolver;
use xSuperr\Promise\PromiseUtils;

$sink = 0;
$ok = static function (mixed $v) use (&$sink): void { ++$sink; };
$fail = static function () use (&$sink): void { --$sink; };
$five = [];
for ($k = 0; $k < 5; ++$k) {
    $five[] = static function (mixed $v) use (&$sink): void { ++$sink; };
}
$reason = new RuntimeException('benchmark');
$inc = static fn(int $v): int => $v + 1;

return [
    'create + resolve' => [
        'ops' => 20_000,
        'impls' => [
            'vow' => static function (int $n): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    $d->resolve($i);
                }
            },
            'quark' => static function (int $n): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new PromiseResolver();
                    $r->resolve($i);
                }
            },
            'xsuperr' => static function (int $n): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new BetterPromiseResolver();
                    $r->resolve($i);
                }
            },
            'react' => static function (int $n): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new React\Promise\Deferred();
                    $d->resolve($i);
                }
            },
            'amp' => static function (int $n): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new DeferredFuture();
                    $d->complete($i);
                }
                EventLoop::run();
            },
        ],
    ],

    'pending, 1 handler' => [
        'ops' => 20_000,
        'impls' => [
            'vow' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    $d->promise->observe($ok, $fail);
                    $d->resolve($i);
                }
            },
            'quark' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new PromiseResolver();
                    $r->getPromise()->onCompletion($ok, $fail);
                    $r->resolve($i);
                }
            },
            'xsuperr' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new BetterPromiseResolver();
                    $r->getPromise()->then($ok)->fail($fail);
                    $r->resolve($i);
                }
            },
            'react' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new React\Promise\Deferred();
                    $d->promise()->then($ok, $fail);
                    $d->resolve($i);
                }
            },
            'amp' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new DeferredFuture();
                    $d->getFuture()->map($ok)->catch($fail);
                    $d->complete($i);
                }
                EventLoop::run();
            },
        ],
    ],

    'pending, 5 handlers' => [
        'ops' => 10_000,
        'impls' => [
            'vow' => static function (int $n) use ($five): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    foreach ($five as $h) {
                        $d->promise->observe($h);
                    }
                    $d->resolve($i);
                }
            },
            'quark' => static function (int $n) use ($five, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new PromiseResolver();
                    $p = $r->getPromise();
                    foreach ($five as $h) {
                        $p->onCompletion($h, $fail);
                    }
                    $r->resolve($i);
                }
            },
            'xsuperr' => static function (int $n) use ($five): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new BetterPromiseResolver();
                    $p = $r->getPromise();
                    foreach ($five as $h) {
                        $p->then($h);
                    }
                    $r->resolve($i);
                }
            },
            'react' => static function (int $n) use ($five): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new React\Promise\Deferred();
                    $p = $d->promise();
                    foreach ($five as $h) {
                        $p->then($h);
                    }
                    $d->resolve($i);
                }
            },
            'amp' => static function (int $n) use ($five): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new DeferredFuture();
                    $f = $d->getFuture();
                    foreach ($five as $h) {
                        $f->map($h);
                    }
                    $d->complete($i);
                }
                EventLoop::run();
            },
        ],
    ],

    'settled, then 1 handler' => [
        'ops' => 20_000,
        'impls' => [
            'vow' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    $d->resolve($i);
                    $d->promise->observe($ok, $fail);
                }
            },
            'quark' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new PromiseResolver();
                    $r->resolve($i);
                    $r->getPromise()->onCompletion($ok, $fail);
                }
            },
            'xsuperr' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new BetterPromiseResolver();
                    $r->resolve($i);
                    $r->getPromise()->then($ok)->fail($fail);
                }
            },
            'react' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new React\Promise\Deferred();
                    $d->resolve($i);
                    $d->promise()->then($ok, $fail);
                }
            },
            'amp' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new DeferredFuture();
                    $d->complete($i);
                    $d->getFuture()->map($ok)->catch($fail);
                }
                EventLoop::run();
            },
        ],
    ],

    'pending, reject' => [
        'ops' => 20_000,
        'impls' => [
            'vow' => static function (int $n) use ($ok, $fail, $reason): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    $d->promise->observe($ok, $fail);
                    $d->reject($reason);
                }
            },
            'quark' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new PromiseResolver();
                    $r->getPromise()->onCompletion($ok, $fail);
                    $r->reject();
                }
            },
            'xsuperr' => static function (int $n) use ($ok, $fail, $reason): void {
                for ($i = 0; $i < $n; ++$i) {
                    $r = new BetterPromiseResolver();
                    $r->getPromise()->then($ok)->fail($fail);
                    $r->reject($reason);
                }
            },
            'react' => static function (int $n) use ($ok, $fail, $reason): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new React\Promise\Deferred();
                    $d->promise()->then($ok, $fail);
                    $d->reject($reason);
                }
            },
            'amp' => static function (int $n) use ($ok, $fail, $reason): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new DeferredFuture();
                    $d->getFuture()->catch($fail);
                    $d->error($reason);
                }
                EventLoop::run();
            },
        ],
    ],

    // Quark and xSuperr cannot chain, so they do what their call sites do today: a resolver per step.
    'chain of 10' => [
        'ops' => 2_000,
        'impls' => [
            'vow' => static function (int $n) use ($inc, $ok): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    $p = $d->promise;
                    for ($k = 0; $k < 10; ++$k) {
                        $p = $p->then($inc);
                    }
                    $p->observe($ok);
                    $d->resolve(0);
                }
            },
            'quark' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $first = $r = new PromiseResolver();
                    for ($k = 0; $k < 10; ++$k) {
                        $next = new PromiseResolver();
                        $r->getPromise()->onCompletion(
                            static function (int $v) use ($next): void { $next->resolve($v + 1); },
                            static function () use ($next): void { $next->reject(); },
                        );
                        $r = $next;
                    }
                    $r->getPromise()->onCompletion($ok, $fail);
                    $first->resolve(0);
                }
            },
            'xsuperr' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $first = $r = new BetterPromiseResolver();
                    for ($k = 0; $k < 10; ++$k) {
                        $next = new BetterPromiseResolver();
                        $r->getPromise()
                            ->then(static function (int $v) use ($next): void { $next->resolve($v + 1); })
                            ->fail(static function (mixed $e) use ($next): void { $next->reject($e); });
                        $r = $next;
                    }
                    $r->getPromise()->then($ok)->fail($fail);
                    $first->resolve(0);
                }
            },
            'react' => static function (int $n) use ($inc, $ok): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new React\Promise\Deferred();
                    $p = $d->promise();
                    for ($k = 0; $k < 10; ++$k) {
                        $p = $p->then($inc);
                    }
                    $p->then($ok);
                    $d->resolve(0);
                }
            },
            'amp' => static function (int $n) use ($inc, $ok): void {
                for ($i = 0; $i < $n; ++$i) {
                    $d = new DeferredFuture();
                    $f = $d->getFuture();
                    for ($k = 0; $k < 10; ++$k) {
                        $f = $f->map($inc);
                    }
                    $f->map($ok);
                    $d->complete(0);
                }
                EventLoop::run();
            },
        ],
    ],

    'all() of 10' => [
        'ops' => 2_000,
        'impls' => [
            'vow' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $ds = $ps = [];
                    for ($k = 0; $k < 10; ++$k) {
                        $ds[] = $d = new Deferred();
                        $ps[] = $d->promise;
                    }
                    Promise::all($ps)->observe($ok, $fail);
                    foreach ($ds as $k => $d) {
                        $d->resolve($k);
                    }
                }
            },
            'quark' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $rs = $ps = [];
                    for ($k = 0; $k < 10; ++$k) {
                        $rs[] = $r = new PromiseResolver();
                        $ps[] = $r->getPromise();
                    }
                    QuarkPromise::all($ps)->onCompletion($ok, $fail);
                    foreach ($rs as $k => $r) {
                        $r->resolve($k);
                    }
                }
            },
            'xsuperr' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $rs = $ps = [];
                    for ($k = 0; $k < 10; ++$k) {
                        $rs[] = $r = new BetterPromiseResolver();
                        $ps[] = $r->getPromise();
                    }
                    PromiseUtils::promiseAll($ps)->then($ok)->fail($fail);
                    foreach ($rs as $k => $r) {
                        $r->resolve($k);
                    }
                }
            },
            'react' => static function (int $n) use ($ok, $fail): void {
                for ($i = 0; $i < $n; ++$i) {
                    $ds = $ps = [];
                    for ($k = 0; $k < 10; ++$k) {
                        $ds[] = $d = new React\Promise\Deferred();
                        $ps[] = $d->promise();
                    }
                    React\Promise\all($ps)->then($ok, $fail);
                    foreach ($ds as $k => $d) {
                        $d->resolve($k);
                    }
                }
            },
        ],
    ],

    // World::requestChunkPopulation(): a resolver per chunk kept in a map, a temporary chunk loader released on
    // success and two players waiting on it, then the population tasks complete in a later tick.
    'quark: chunk requests' => [
        'ops' => 5_000,
        'impls' => [
            'vow' => static function (int $n) use (&$sink): void {
                $map = [];
                for ($i = 0; $i < $n; ++$i) {
                    $map[$i] = $d = new Deferred();
                    $d->promise->observe(static function () use (&$sink, $i): void { $sink += $i; });
                    for ($player = 0; $player < 2; ++$player) {
                        $d->promise->observe(
                            static function (mixed $chunk) use (&$sink, $i, $player): void { $sink += $i + $player; },
                            static function () use (&$sink): void { --$sink; },
                        );
                    }
                }
                foreach ($map as $i => $d) {
                    unset($map[$i]);
                    $d->resolve($i);
                }
            },
            'quark' => static function (int $n) use (&$sink): void {
                $map = [];
                $noop = static function (): void {};
                for ($i = 0; $i < $n; ++$i) {
                    $map[$i] = $r = new PromiseResolver();
                    $r->getPromise()->onCompletion(static function () use (&$sink, $i): void { $sink += $i; }, $noop);
                    for ($player = 0; $player < 2; ++$player) {
                        $r->getPromise()->onCompletion(
                            static function (mixed $chunk) use (&$sink, $i, $player): void { $sink += $i + $player; },
                            static function () use (&$sink): void { --$sink; },
                        );
                    }
                }
                foreach ($map as $i => $r) {
                    unset($map[$i]);
                    $r->resolve($i);
                }
            },
            'xsuperr' => static function (int $n) use (&$sink): void {
                $map = [];
                for ($i = 0; $i < $n; ++$i) {
                    $map[$i] = $r = new BetterPromiseResolver();
                    $r->getPromise()->then(static function () use (&$sink, $i): void { $sink += $i; });
                    for ($player = 0; $player < 2; ++$player) {
                        $r->getPromise()
                            ->then(static function (mixed $chunk) use (&$sink, $i, $player): void { $sink += $i + $player; })
                            ->fail(static function () use (&$sink): void { --$sink; });
                    }
                }
                foreach ($map as $i => $r) {
                    unset($map[$i]);
                    $r->resolve($i);
                }
            },
            'react' => static function (int $n) use (&$sink): void {
                $map = [];
                for ($i = 0; $i < $n; ++$i) {
                    $map[$i] = $d = new React\Promise\Deferred();
                    $d->promise()->then(static function () use (&$sink, $i): void { $sink += $i; });
                    for ($player = 0; $player < 2; ++$player) {
                        $d->promise()->then(
                            static function (mixed $chunk) use (&$sink, $i, $player): void { $sink += $i + $player; },
                            static function () use (&$sink): void { --$sink; },
                        );
                    }
                }
                foreach ($map as $i => $d) {
                    unset($map[$i]);
                    $d->resolve($i);
                }
            },
        ],
    ],

    // NetworkSession + ChunkCache: queue a batch, flush it once compression finishes and read the payload back.
    'quark: compressed batch' => [
        'ops' => 20_000,
        'impls' => [
            'vow' => static function (int $n) use (&$sink): void {
                $queue = new SplQueue();
                $flush = static function () use ($queue, &$sink): void {
                    while (!$queue->isEmpty() && $queue->bottom()->isFulfilled()) {
                        $sink += strlen($queue->dequeue()->value());
                    }
                };
                for ($i = 0; $i < $n; ++$i) {
                    $d = new Deferred();
                    $queue->enqueue($d->promise);
                    $d->promise->observe($flush);
                    $d->resolve('payload');
                }
            },
            'compress-batch-promise' => static function (int $n) use (&$sink): void {
                $queue = new SplQueue();
                $flush = static function () use ($queue, &$sink): void {
                    while (!$queue->isEmpty() && $queue->bottom()->hasResult()) {
                        $sink += strlen($queue->dequeue()->getResult());
                    }
                };
                for ($i = 0; $i < $n; ++$i) {
                    $p = new CompressBatchPromise();
                    $queue->enqueue($p);
                    $p->onResolve($flush);
                    $p->resolve('payload');
                }
            },
        ],
    ],
];
