# Benchmark results

PHP 8.4.14, Windows AMD64, 2026-11-06. Median of 25 samples per cell: 5 rounds in which every library takes a turn in a fresh process, 5 samples each after 3 warmups. ± is the median absolute deviation, and the ratio compares against Vow (higher is slower).

## Time per operation, JIT off

| Scenario | vow | quark | compress-batch-promise | xsuperr | react | amp |
| --- | --- | --- | --- | --- | --- | --- |
| create + resolve | 285 ns ±4% (1.00×) | 389 ns ±3% (1.37×) | – | 387 ns ±1% (1.36×) | 1,134 ns ±0% (3.98×) | 451 ns ±1% (1.58×) |
| pending, 1 handler | 709 ns ±1% (1.00×) | 675 ns ±1% (0.95×) | – | 733 ns ±3% (1.03×) | 3,282 ns ±1% (4.63×) | 9,227 ns ±15% (13.01×) |
| pending, 5 handlers | 1,734 ns ±2% (1.00×) | 1,641 ns ±2% (0.95×) | – | 1,346 ns ±2% (0.78×) | 11,244 ns ±2% (6.48×) | 14,095 ns ±6% (8.13×) |
| settled, then 1 handler | 402 ns ±1% (1.00×) | 535 ns ±1% (1.33×) | – | 650 ns ±1% (1.61×) | 1,599 ns ±3% (3.98×) | 9,413 ns ±13% (23.40×) |
| pending, reject | 725 ns ±1% (1.00×) | 635 ns ±1% (0.88×) | – | 742 ns ±2% (1.02×) | 3,456 ns ±2% (4.76×) | 2,452 ns ±12% (3.38×) |
| chain of 10 | 6,867 ns ±1% (1.00×) | 9,139 ns ±1% (1.33×) | – | 9,913 ns ±2% (1.44×) | 24,326 ns ±9% (3.54×) | 36,752 ns ±9% (5.35×) |
| all() of 10 | 10,740 ns ±4% (1.00×) | 11,057 ns ±2% (1.03×) | – | 11,938 ns ±1% (1.11×) | 42,261 ns ±1% (3.94×) | – |
| quark: chunk requests | 2,985 ns ±10% (1.00×) | 3,782 ns ±9% (1.27×) | – | 3,065 ns ±17% (1.03×) | 21,631 ns ±8% (7.25×) | – |
| quark: compressed batch | 914 ns ±1% (1.00×) | – | 622 ns ±0% (0.68×) | – | – | – |

## Time per operation, JIT tracing

| Scenario | vow | quark | compress-batch-promise | xsuperr | react | amp |
| --- | --- | --- | --- | --- | --- | --- |
| create + resolve | 159 ns ±5% (1.00×) | 331 ns ±6% (2.08×) | – | 334 ns ±5% (2.09×) | 789 ns ±5% (4.95×) | 317 ns ±2% (1.99×) |
| pending, 1 handler | 348 ns ±2% (1.00×) | 531 ns ±3% (1.53×) | – | 576 ns ±3% (1.66×) | 2,435 ns ±1% (7.01×) | 8,163 ns ±16% (23.49×) |
| pending, 5 handlers | 689 ns ±2% (1.00×) | 828 ns ±2% (1.20×) | – | 752 ns ±3% (1.09×) | 7,935 ns ±1% (11.52×) | 11,843 ns ±9% (17.18×) |
| settled, then 1 handler | 203 ns ±5% (1.00×) | 446 ns ±8% (2.20×) | – | 556 ns ±11% (2.74×) | 1,040 ns ±4% (5.13×) | 8,089 ns ±17% (39.94×) |
| pending, reject | 346 ns ±2% (1.00×) | 498 ns ±4% (1.44×) | – | 583 ns ±5% (1.69×) | 2,638 ns ±2% (7.62×) | 1,819 ns ±14% (5.26×) |
| chain of 10 | 3,976 ns ±15% (1.00×) | 8,162 ns ±9% (2.05×) | – | 8,072 ns ±4% (2.03×) | 18,442 ns ±8% (4.64×) | 32,007 ns ±9% (8.05×) |
| all() of 10 | 6,099 ns ±4% (1.00×) | 9,650 ns ±6% (1.58×) | – | 10,225 ns ±9% (1.68×) | 30,607 ns ±3% (5.02×) | – |
| quark: chunk requests | 2,719 ns ±20% (1.00×) | 3,315 ns ±9% (1.22×) | – | 3,316 ns ±19% (1.22×) | 20,503 ns ±10% (7.54×) | – |
| quark: compressed batch | 485 ns ±3% (1.00×) | – | 376 ns ±4% (0.78×) | – | – | – |

## Memory

Bytes per pending promise with one success and one failure handler (handlers shared, so this is library overhead only), bytes still held per settled promise when the caller keeps its resolver, and heap growth across 20 rounds of 10,000 promises after a warm-up round.

| Library | Pending + handler | Settled, kept | Growth over 200k promises |
| --- | --- | --- | --- |
| vow | 409 B | 193 B | 376 B |
| quark | 705 B | 273 B | 376 B |
| compress-batch-promise | 356 B | 140 B | 376 B |
| xsuperr | 705 B | 273 B | 376 B |
| react | 4728 B | 1984 B | 376 B |
| amp | 3184 B | 415 B | 376 B |
