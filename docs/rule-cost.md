# Execution cost of the rules

The number of opcodes is a poor proxy for the cost of code: of 19 before/after pairs measured in
melentev-av/opmin#66, 11 had opposite signs by opcodes and by machine instructions (string interpolation
instead of `.`: +1 opcode, −13…−22 % instructions; `switch` → `match`: −11 opcodes, 0…+16 %). The rules are
therefore judged by their execution cost, measured here.

## How it is measured

```bash
bin/bench --cost                        # every rule, PHP 8.1–8.5 in Docker
bin/bench --cost --versions=8.4,8.5     # some versions
bin/bench --cost --rules=Ifm,Orid       # some rules (keys of bench/cost/cases.php)
bin/bench --cost --local                # php and valgrind on PATH (Linux) instead of Docker
bin/bench --cost --report               # the report of the last measurement again
```

- **Cases** (`bench/cost/cases.php`): for each of opmin's rules and of the standard rules in
  `rector.standard.rules`, the code before in several forms and sizes, and several inputs per case — every
  branch, a miss, `null` and an object, an empty and a large array.
- **The code after is written by the rule itself** (Rector, `phpVersion(80100)`, so that rules limited by
  `php.target` are measured on every version too). A case where the rule does not fire is skipped and
  listed. Before and after must return the same on every input.
- **Cost** — machine instructions per call (callgrind `Ir`), without JIT and with the tracing JIT
  (`opcache.jit=tracing`, 64M buffer), `zend.assertions=-1`, in the official `php:<ver>-cli` images with
  valgrind (`bench/cost/Dockerfile`). Per call = (segment of 3000 calls − segment of 1000) / 2000 after a
  warm-up, the segments split by callgrind dumps at `usleep(0)`: the start of PHP, the JIT compilation and
  the marker cancel out.
- **Every case, input and variant runs alone**: a file of its own (it loads only its own code), a process
  of its own, address randomization off (`setarch -R`, the container runs with `seccomp=unconfined`).
  Measured together, the same case differed by up to 2× without JIT and changed sign with the JIT (the
  JIT state is shared by the process); with randomization the same file differed by up to 20
  instructions per call between runs. Now three runs give the same numbers, and the difference after −
  before of a case does not depend on which other cases are measured.
- **Tolerance** (agreed in #66): a rule is worth it if it is cheaper in the sum over the inputs and no
  input costs more than 5 % **and** more than 15 instructions per call — in both JIT modes.

What this does not show: real code (the cases are synthetic; real inputs decide rules whose sign depends
on the input — `IfChainToMatch` on the first label), time (instructions matched the sign of time in 14 of
14 pairs of the spike in #66), the cost of reading the code.

## Results

Measured 2026-10-15 (`bin/bench --cost`, Rector 2.7, PHP 8.1.34 … 8.5.0 images), 118 cases × inputs. Sum of
the cost over the inputs of a rule, after vs before, the range over PHP 8.1–8.5; the last column lists the
inputs that cost more beyond the tolerance, before → after instructions per call.

| Rule | key | Without JIT | Tracing JIT | Inputs beyond the tolerance |
|---|---|---|---|---|
| `FullyQualifyGlobalCallsRector` | `Fq` | −18…−27 % | −17…−27 % | none |
| `ExtractRepeatedPropertyFetchRector` | `Prop` | −8 % | −1…−2 % | none |
| `ExtractRepeatedArrayDimFetchRector` | `Dim` | −6 % | −5 % | none |
| `HoistLoopInvariantCountRector` | `Hoist` | −23…−38 % | −10…−13 % | none |
| `RemoveUnusedClosureUseRector` | `Cluse` | −8…−9 % | −9…−10 % | none |
| `SprintfToConcatRector` | `Sprintf` | 8.1–8.3: −24…−26 %; 8.4–8.5: +18 % | 8.1–8.3: −21…−24 %; 8.4–8.5: +41…+42 % | 8.4–8.5: every form (+7…+74 %) — the rule runs only below 8.4 |
| `ArrayPushToAssignRector` | `Apush` | −37…−40 % | −36…−42 % | none |
| `StrStartsWithRector` | `Starts` | −34…−36 % | −41…−46 % | none |
| `OrIdenticalToInArrayRector` | `Orid` | −48…−49 % | −58…−60 % | none |
| `IfChainToMatchRector` (string labels) | `Ifm` | −44 % | −58…−59 % | the first label: 215 → 250 / 87 → 106 instructions (+16…+22 %) |
| `NullCheckTernaryToNullsafeRector` (off) | `Nulls` | −4 % | +20…+22 % | with JIT: `null` 62 → 100 (+61 %), an object +6…+11 % |
| `SwitchToMatchRector` (off) | `Sw` | +11 % | +27…+29 % | almost every input, `match` costs 10–56 % more (a `switch` of ≥ 5 int or ≥ 2 string labels already jumps by a table) |
| std `SingleInArrayToCompareRector` | `StdSingleInArray` | −7 % | +33…+36 % | with JIT: loose `== 'a'` +19…+105 %, strict miss +23 % |
| std `SimplifyFuncGetArgsCountRector` | `StdFuncGetArgsCount` | −19…−28 % | −39…−41 % | no arguments: +37 % / +35 % |
| std `StrlenZeroToIdenticalEmptyStringRector` | `StdStrlenZero` | −2 % | +26…+29 % | with JIT: `$s === ''` +22…+29 % |
| std `UnnecessaryTernaryExpressionRector` | `StdTernary` | −5 % | +5…+8 % | none (+3…+9 instructions) |
| std `RemoveUnusedVariableAssignRector` | `StdUnusedAssign` | −42…−44 % | −68…−69 % | none |
| std `RemoveDeadConditionAboveReturnRector` | `StdDeadCondition` | −6 % | −5 % | none |

`IfChainToMatch` with int labels does not fire any more; its int cases stay in `cases.php` and are listed as
not fired. Per input: `runtime/bench-cost/results.tsv` after a run.

## Rules decided by these numbers

- `SwitchToMatchRector` and `NullCheckTernaryToNullsafeRector` are off by default (`false` in
  `rector.custom_rules`).
- `IfChainToMatchRector` rewrites only chains of string labels: int chains cost more as `match`.
- `SprintfToConcatRector` runs only for a `php.target` below 8.4: from 8.4 the compiler makes the same
  rope out of `sprintf()` itself, and the concatenation costs more.
- Open: `SingleInArrayToCompareRector` and `StrlenZeroToIdenticalEmptyStringRector` in `rector.standard.rules`
  cost more with the JIT beyond the tolerance (the standard group is off by default).
