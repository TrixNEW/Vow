Unmodified copies of the promise implementations Vow is benchmarked against. They are only used by the benchmarks
and are excluded from the package.

| Directory | Source | License |
|-----------|--------|---------|
| `quark/` | [Bedrock-Phanatics/Quark](https://github.com/Bedrock-Phanatics/Quark) `v6.0.0` @ `ee21008`, `src/promise/` and `CompressBatchPromise` | LGPL-3.0 |
| `xsuperr/` | [xSuperr/Promise](https://github.com/xSuperr/Promise) @ `c3b4538` | Apache-2.0 |
| `pocketmine/` | Quark's `PromiseSharedData` under the `pocketmine\promise` namespace, which xSuperr/Promise depends on | LGPL-3.0 |

`quark/Utils.php` only provides `Utils::promoteKeys()`, which Quark's `Promise::all()` calls.
