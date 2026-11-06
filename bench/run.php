<?php

declare(strict_types=1);

// Usage: php run.php [--rounds=5] [--samples=5] [--filter=text] [--only=vow,quark] [--no-jit|--jit] [--out=RESULTS.md]
// Every measurement runs in a fresh process so JIT traces and heap state never leak between libraries, and the
// libraries take turns across rounds so machine noise and thermal drift hit all of them alike.

use Amp\DeferredFuture;
use quark\network\mcpe\compression\CompressBatchPromise;
use quark\promise\PromiseResolver;
use Revolt\EventLoop;
use Vow\Deferred;
use xSuperr\Promise\BetterPromiseResolver;

require __DIR__ . '/vendor/autoload.php';

const WARMUP = 3;

$options = getopt('', ['child:', 'impl:', 'samples:', 'rounds:', 'filter:', 'only:', 'no-jit', 'jit', 'out:', 'memory']);

if (isset($options['child'])) {
    $scenarios = array_values(require __DIR__ . '/scenarios.php');
    $scenario = $scenarios[(int) $options['child']];
    $run = $scenario['impls'][$options['impl']];
    $samples = (int) ($options['samples'] ?? 15);
    for ($i = 0; $i < WARMUP; ++$i) {
        $run($scenario['ops']);
    }
    $times = [];
    for ($i = 0; $i < $samples; ++$i) {
        gc_collect_cycles();
        $start = hrtime(true);
        $run($scenario['ops']);
        $times[] = (hrtime(true) - $start) / $scenario['ops'];
    }
    echo json_encode(['times' => $times, 'jit' => jitEnabled()]);
    exit;
}

if (isset($options['memory'])) {
    echo json_encode(measureMemory((string) $options['impl']));
    exit;
}

function jitEnabled(): bool
{
    return function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false);
}

/**
 * Bytes per pending promise with one handler, bytes kept per settled promise, and growth over a sustained run.
 *
 * @return array{pending: float, settled: float, sustained: float}
 */
function measureMemory(string $impl): array
{
    $ok = static function (mixed $v): void {};
    $fail = static function (): void {};
    $makers = [
        'vow' => [
            static function () use ($ok, $fail): Deferred { $d = new Deferred(); $d->promise->observe($ok, $fail); return $d; },
            static fn(Deferred $d) => $d->resolve(1),
        ],
        'quark' => [
            static function () use ($ok, $fail): PromiseResolver { $r = new PromiseResolver(); $r->getPromise()->onCompletion($ok, $fail); return $r; },
            static fn(PromiseResolver $r) => $r->resolve(1),
        ],
        'xsuperr' => [
            static function () use ($ok, $fail): BetterPromiseResolver { $r = new BetterPromiseResolver(); $r->getPromise()->then($ok)->fail($fail); return $r; },
            static fn(BetterPromiseResolver $r) => $r->resolve(1),
        ],
        'react' => [
            static function () use ($ok, $fail): React\Promise\Deferred { $d = new React\Promise\Deferred(); $d->promise()->then($ok, $fail); return $d; },
            static fn(React\Promise\Deferred $d) => $d->resolve(1),
        ],
        'amp' => [
            static function () use ($ok, $fail): DeferredFuture { $d = new DeferredFuture(); $d->getFuture()->map($ok)->catch($fail); return $d; },
            static fn(DeferredFuture $d) => $d->complete(1),
        ],
        'compress-batch-promise' => [
            static function () use ($ok): CompressBatchPromise { $p = new CompressBatchPromise(); $p->onResolve($ok); return $p; },
            static fn(CompressBatchPromise $p) => $p->resolve('x'),
        ],
    ];
    [$make, $settle] = $makers[$impl];
    $n = 10_000;

    $held = array_fill(0, $n, null);
    gc_collect_cycles();
    $base = memory_get_usage();
    for ($i = 0; $i < $n; ++$i) {
        $held[$i] = $make();
    }
    $pending = (memory_get_usage() - $base) / $n;
    foreach ($held as $item) {
        $settle($item);
    }
    if ($impl === 'amp') {
        EventLoop::run();
    }
    gc_collect_cycles();
    $settled = (memory_get_usage() - $base) / $n;
    $held = array_fill(0, $n, null);

    $round = static function () use ($make, $settle, $impl, $n): void {
        for ($i = 0; $i < $n; ++$i) {
            $settle($make());
        }
        if ($impl === 'amp') {
            EventLoop::run();
        }
    };
    $round();
    gc_collect_cycles();
    $before = memory_get_usage();
    for ($r = 0; $r < 20; ++$r) {
        $round();
    }
    gc_collect_cycles();

    return ['pending' => $pending, 'settled' => $settled, 'sustained' => (float) (memory_get_usage() - $before)];
}

/**
 * @param list<string> $args
 * @return array<string, mixed>
 */
function child(array $flags, array $args): array
{
    $command = [PHP_BINARY, '-d', 'memory_limit=1G', '-d', 'zend.assertions=-1', ...$flags, __FILE__, ...$args];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Could not start ' . implode(' ', $command));
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);
    $data = json_decode((string) $out, true);
    if (!is_array($data)) {
        throw new RuntimeException("Benchmark child failed: $out $err");
    }
    return $data;
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    $mid = intdiv($count, 2);
    return $count % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

$samples = (int) ($options['samples'] ?? 5);
$rounds = (int) ($options['rounds'] ?? 5);
$filter = $options['filter'] ?? null;
$only = isset($options['only']) ? explode(',', (string) $options['only']) : null;
$opcache = extension_loaded('Zend OPcache') ? [] : ['-d', 'zend_extension=opcache'];
$modes = [
    'JIT off' => [...$opcache, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=disable'],
    'JIT tracing' => [...$opcache, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M'],
];
if (isset($options['no-jit'])) {
    unset($modes['JIT tracing']);
}
if (isset($options['jit'])) {
    unset($modes['JIT off']);
}

$scenarios = require __DIR__ . '/scenarios.php';
$impls = ['vow', 'quark', 'compress-batch-promise', 'xsuperr', 'react', 'amp'];
if ($only !== null) {
    $impls = array_values(array_intersect($impls, $only));
}

$report = [
    '# Benchmark results',
    '',
    sprintf('PHP %s, %s %s, %s. Median of %d samples per cell: %d rounds in which every library takes a turn in a fresh process, %d samples each after %d warmups. ± is the median absolute deviation, and the ratio compares against Vow (higher is slower).', PHP_VERSION, PHP_OS_FAMILY, php_uname('m'), date('Y-m-d'), $rounds * $samples, $rounds, $samples, WARMUP),
    '',
];

foreach ($modes as $mode => $flags) {
    fwrite(STDERR, "== $mode\n");
    $header = '| Scenario | ' . implode(' | ', $impls) . ' |';
    $report[] = "## Time per operation, $mode";
    $report[] = '';
    $report[] = $header;
    $report[] = '|' . str_repeat(' --- |', count($impls) + 1);
    $index = 0;
    foreach ($scenarios as $name => $scenario) {
        $position = $index++;
        if ($filter !== null && !str_contains($name, (string) $filter)) {
            continue;
        }
        $times = [];
        for ($round = 0; $round < $rounds; ++$round) {
            foreach ($impls as $impl) {
                if (!isset($scenario['impls'][$impl])) {
                    continue;
                }
                $data = child($flags, ['--child=' . $position, '--impl=' . $impl, '--samples=' . $samples]);
                if ($data['jit'] !== ($mode === 'JIT tracing')) {
                    throw new RuntimeException("JIT state mismatch for $mode");
                }
                $times[$impl] = [...$times[$impl] ?? [], ...$data['times']];
            }
        }
        $results = [];
        foreach ($times as $impl => $values) {
            $median = median($values);
            $mad = median(array_map(static fn(float $t): float => abs($t - $median), $values));
            $results[$impl] = [$median, $mad];
            fwrite(STDERR, sprintf("%-28s %-24s %9.1f ns ±%.1f%%\n", $name, $impl, $median, $mad / $median * 100));
        }
        $baseline = $results['vow'][0] ?? null;
        $cells = [];
        foreach ($impls as $impl) {
            if (!isset($results[$impl])) {
                $cells[] = '–';
                continue;
            }
            [$median, $mad] = $results[$impl];
            $ratio = $baseline !== null ? sprintf(' (%.2f×)', $median / $baseline) : '';
            $cells[] = sprintf('%s ns ±%.0f%%%s', number_format($median, 0), $mad / $median * 100, $ratio);
        }
        $report[] = "| $name | " . implode(' | ', $cells) . ' |';
    }
    $report[] = '';
}

if ($filter === null) {
    fwrite(STDERR, "== memory\n");
    $report[] = '## Memory';
    $report[] = '';
    $report[] = 'Bytes per pending promise with one success and one failure handler (handlers shared, so this is library overhead only), bytes still held per settled promise when the caller keeps its resolver, and heap growth across 20 rounds of 10,000 promises after a warm-up round.';
    $report[] = '';
    $report[] = '| Library | Pending + handler | Settled, kept | Growth over 200k promises |';
    $report[] = '| --- | --- | --- | --- |';
    foreach ($impls as $impl) {
        $memory = child($modes[array_key_first($modes)], ['--memory', '--impl=' . $impl]);
        $report[] = sprintf('| %s | %d B | %d B | %s B |', $impl, $memory['pending'], $memory['settled'], number_format($memory['sustained']));
        fwrite(STDERR, sprintf("%-24s pending %d B, settled %d B, growth %d B\n", $impl, $memory['pending'], $memory['settled'], $memory['sustained']));
    }
    $report[] = '';
}

$out = $options['out'] ?? null;
if ($out !== null) {
    file_put_contents((string) $out, implode("\n", $report));
}
echo implode("\n", $report), "\n";
