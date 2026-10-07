# Standard Rector rules and opcodes

Stage A applies opmin's own rules by default. The standard rules of Rector (`rector.standard`) are off: they were
written for upgrades and code quality, not for opcodes, and know nothing of opmin's safety conditions. This page
records which of them save opcodes, measured on PHP 8.1 and 8.5 with Rector 2.7.0 — the basis of the list
`opmin init` writes and of the README.

Every claim here comes from a count, not from reading the rule:

```bash
bin/bench --only=standard                       # each rule on code it rewrites, ops_opt before → after
bin/bench --only=standard --php=/path/to/php8.1
opmin optimize --with-standard-rector --dry-run # every rule of the configured sets on a project
```

## Rules measured on code they rewrite

`bin/bench --only=standard`, `ops_opt` of a method body before → after (the same on PHP 8.1 and 8.5 unless noted):

| Rule | Opcodes | In the default list |
|---|---|---|
| `CodeQuality\…\SingleInArrayToCompareRector` (`in_array($v, ['a'], true)` → `===`) | −7 on 8.5, −4 on 8.1 | yes |
| `CodeQuality\…\SimplifyFuncGetArgsCountRector` (`count(func_get_args())` → `func_num_args()`) | −3 | yes |
| `CodeQuality\…\StrlenZeroToIdenticalEmptyStringRector` (`strlen($s) === 0` → `$s === ''`) | −3 | yes |
| `CodeQuality\…\UnnecessaryTernaryExpressionRector` (`$a === 1 ? true : false` → `$a === 1`) | −2 | yes |
| `DeadCode\…\RemoveDeadConditionAboveReturnRector` | −2 | yes |
| `DeadCode\…\RemoveUnusedVariableAssignRector` | −1 | yes |
| `CodeQuality\…\CompactToVariablesRector` (`compact('a')` → `['a' => $a]`) | −2 | in CODE_QUALITY |
| `CodeQuality\…\SimplifyIfReturnBoolRector` | 0 | removed |
| `EarlyReturn\…\RemoveAlwaysElseRector` | 0 | — |
| `CodeQuality\…\CombinedAssignRector` (`$n = $n + $v` → `$n += $v`) | **+1** | removed |
| `CodeQuality\…\ForRepeatedCountToOwnVariableRector` (Rector's own count hoisting, unqualified `count()`) | **+1** | — (opmin's `HoistLoopInvariantCountRector` hoists `\count()` at 0) |
| `EarlyReturn\…\ChangeNestedIfsToEarlyReturnRector` | no change on a nested `if` with returns | removed |
| `CodeQuality\…\InlineConstructorDefaultToPropertyRector` | changes properties, not function bodies: nothing to count | removed |

A rule without a gain is rolled back by the pipeline anyway; the list keeps only rules worth the run time.

## The sets on the playground

`opmin optimize --with-standard-rector --dry-run` with opmin's own rules switched off, on the seeds of
`vanilla-composer` (16 files, 56 functions, PHP 8.5): 269 rules of `DEAD_CODE`, `EARLY_RETURN`, `CODE_QUALITY`,
`UP_TO_PHP_83` and the list, 1.5 minutes, **427 → 405 opcodes**.

| Rule | Function | Result |
|---|---|---|
| `SingleInArrayToCompareRector` | `Status::isActive` | kept, −13 |
| `SimplifyFuncGetArgsCountRector` | `Traps::argCount` | kept, −3 |
| `CompactToVariablesRector` | `Traps::context` | kept, −3 |
| `StrlenZeroToIdenticalEmptyStringRector` | `is_blank` | kept, −3 |
| `ForRepeatedCountToOwnVariableRector` | `Obvious::sumEven`, `RuleWins::total` | rolled back: +1 |
| `RemoveAlwaysElseRector` | `Obvious::describe`, `clamp` | rolled back: no gain |
| `SimplifyUselessVariableRector` | `Obvious::priceOf` | rolled back: no gain |
| `ClosureToArrowFunctionRector` | `Shapes::squares` | rolled back: no gain |
| `ReadOnlyClassRector` (UP_TO_PHP_83) | five classes | rolled back: changes outside functions without a gain |

The other rules did not change the seeds.

## Findings about Rector 2.7

- **`EARLY_RETURN` is empty**: `config/set/early-return.php` registers no rules ("all early return rules were moved to
  code quality set or deprecated"). It is no longer in the default `sets`.
- Rules of a set are applied one by one by importing the set and skipping every other rule of it: a rule the set
  configures keeps its configuration. Rector hashes its cache by the registered rules, which are the same for every
  rule of one set — opmin gives each rule a cache directory of its own (otherwise the first rule of a set hides the
  files from the rest).
- Rector re-prints a function it changed (`SimplifyIfReturnBoolRector` turns a one-line function into several lines);
  the readability threshold `min_gain_per_line` counts those lines.
- `UP_TO_PHP_TARGET` expands to `LevelSetList::UP_TO_PHP_8x` of `php.target`; most of its rules change declarations
  (readonly classes, typed constants), which opmin rolls back: they save no opcodes in function bodies.
