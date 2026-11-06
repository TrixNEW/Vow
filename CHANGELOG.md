# Changelog

## 1.0.1

- Release completed combinator results and unused values while other inputs remain pending.
- Detect circular async adoption and keep long async adoption chains off the call stack.
- Avoid reporting rejections already inspected during parent callbacks.

## 1.0.0

First release: `Promise`, `Deferred`, `then()`/`catch()`/`finally()`/`observe()`, `all()`/`allSettled()`/`race()`/`any()`,
cancellation, unhandled rejection reporting and fiber based `async()`/`await()`.
