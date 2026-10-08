# opmin optimize: 20261007-120000

**40 → 33 opcodes** (−7, −17.5%) in 2 function(s); 3 change(s) rolled back.

- Full run of the project's tests: passes.
- Patch: `runs/20261007-120000/opmin.patch`.

> [!WARNING]
> php changed since the run 20261006-100000: 8.4.11 → 8.4.12 (opcode counts are not comparable).

## Changed functions

| Function | Opcodes | Saved by | Proof | Diff-test coverage | Project tests | Flags |
|---|---|---|---|---|---|---|
| `App\Cart::total`<br>src/Cart.php | 20 → 13 | FullyQualifyGlobalCallsRector, LLM | tests | 80%, 210 inputs | 3 | io |
| `App\Cart::sum`<br>src/Cart.php | 12 → 12 (executed: fewer) | HoistLoopInvariantCountRector | diff-tested, time -12.5% | 92.5%, 180 inputs | 0 |  |

## Fewer executed opcodes, not static ones

These changes (`HoistLoopInvariantCountRector`: an invariant out of a loop condition) may keep the static
count, but fewer opcodes run per loop iteration.

- `App\Cart::sum`

## Rolled back

### Differential test found a difference (1)

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

### Readability thresholds (1)

| Function | Rule | Reason |
|---|---|---|
| `App\Cart::total`<br>src/Cart.php | LLM | cyclomatic complexity grew by 1 |

### No opcodes saved (1)

| Function | Rule | Reason |
|---|---|---|
| `App\Cart::name`<br>src/Cart.php | FullyQualifyGlobalCallsRector | gain 0 is below readability.min_gain |

## Environment

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

## Notes

- Formatter: vendor/bin/pint {files} (pint.json)
