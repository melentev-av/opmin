# opmin optimize: 20261007-120000

opmin rewrote functions so that PHP compiles them to fewer opcodes, and kept a change only when it
saved opcodes and the function behaved exactly as before. Opcodes are counted per function with
OPcache of `php.binary` (see Environment).

**40 → 33 opcodes** (−7, −17.5%) in 2 function(s); 3 change(s) rolled back.

- Full run of the project's tests: passes.
- Patch: `runs/20261007-120000/opmin.patch`.

> [!WARNING]
> php changed since the run 20261006-100000: 8.4.11 → 8.4.12 (opcode counts are not comparable).

## Changed functions

| Function | Opcodes before → after | Saved by | Proven by | Behavior check | Project tests | Flags |
|---|---|---|---|---|---|---|
| `App\Cart::total`<br>src/Cart.php | 20 → 13 | FullyQualifyGlobalCallsRector, LLM | tests | 210 inputs, 80% of the original reached | 3 | io |
| `App\Cart::sum`<br>src/Cart.php | 12 → 12 (executed: fewer) | HoistLoopInvariantCountRector | diff-tested, time -12.5% | 180 inputs, 92.5% of the original reached | 0 |  |

- **Saved by**: the rules whose changes were kept; "LLM" is a rewrite by the LLM stage.
- **Proven by**: `diff-tested` — the original and the changed function were called with the same
  generated inputs and behaved the same (result, output, exceptions, warnings, changed arguments);
  `tests` — the generated inputs could not prove it (side effects, too little of the code reached),
  the project's tests that run the function pass; `unverified` — not proven, kept because
  `verification.allow_unverified` is on. "time ±N%" is the speed change measured by `--guard-perf`.
- **Behavior check**: how many generated inputs both versions got, and how much of the original
  function's code those inputs reached. Below `verification.min_branch_coverage` the generated inputs
  alone do not prove a change. "—": not checked this way.
- **Project tests**: how many of the project's tests run the function; "—": no test runner.
- **Flags**: what in the function or the project limits the rewrites; the function:
  - `io` — does I/O (files, network, processes, output headers).

## Fewer executed opcodes, not static ones

These changes (`HoistLoopInvariantCountRector`: an invariant out of a loop condition) may keep the static
count, but fewer opcodes run per loop iteration.

- `App\Cart::sum`

## Rolled back

Changes that were tried and taken back: the code of these functions stays as it was, or as an earlier
kept change left it. Grouped by the reason.

### Differential test found a difference (1)

The original and the changed function were called with the same generated inputs, and on some input they behaved differently (result, output, exception, warning or changed arguments). A counterexample below shows such an input.

| Function | Rule | Reason |
|---|---|---|
| `App\Cart::price`<br>src/Cart.php | FullyQualifyGlobalCallsRector | differential test: return differs: 1.5 vs 1 |

Counterexample for `App\Cart::price` (test: `runs/20261007-120000/counterexamples/CartPriceTest.php`):

```json
{
    "input": {
        "args": [
            {
                "kind": "string",
                "value": "1.5"
            }
        ]
    }
}
```

### Not worth the code change (1)

The change saved opcodes and behaved the same, but it makes the code harder to read than the gain is worth (`readability.*`): too few opcodes saved per changed line, more branches or deeper nesting, or a forbidden construct.

| Function | Rule | Reason |
|---|---|---|
| `App\Cart::total`<br>src/Cart.php | LLM | cyclomatic complexity grew by 1 (more branches; readability.max_cyclomatic_increase is 0) |

### No opcodes saved (1)

The change saved no opcodes, or added some.

| Function | Rule | Reason |
|---|---|---|
| `App\Cart::name`<br>src/Cart.php | FullyQualifyGlobalCallsRector | saves no opcodes |

## Environment

What the counts and checks depend on: opcode counts are comparable only between runs with the same
PHP version and OPcache optimizer settings.

| | |
|---|---|
| PHP runtime (`php.binary`) | 8.4.12 |
| `php.target` | 8.3 |
| OPcache optimizer settings | a1b2c3d4e5f6 |
| opmin | 0.2.0 |
| Rector | 2.7.0 |
| PHPStan of the project | 2.1.30 |
| Test runner | phpunit |
| Formatter | vendor/bin/pint {files} (pint.json) |
