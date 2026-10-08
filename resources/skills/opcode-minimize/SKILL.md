---
name: opcode-minimize
description: Rewrite PHP functions so they compile to fewer opcodes without changing behavior, one function at a time, with opmin counting and verifying every candidate. Use when the user asks to minimize or reduce PHP opcodes, to run the LLM stage of opmin, or to micro-optimize PHP functions with opmin.
---

<!-- opmin-skill-version: {{version}} -->

# Minimize opcodes with opmin (Stage B)

You propose rewritten functions; `opmin` decides. A candidate is kept only when it has strictly fewer
opcodes, passes the readability and signature rules and proves the same behavior (PHPStan, the
project's tests, differential tests against the original). Never claim a gain that `apply-candidate`
did not report.

## 0. Before you start

1. `opmin --version` must print `{{version}}` — the version this skill is written for. If it differs,
   run `opmin skill:update` (add `--global` if the skill is installed in `~/.claude/skills`) and
   reload the skill.
2. The git working tree must be clean: every accepted candidate becomes a commit. If it is not,
   ask the user to commit or stash; do not do it yourself.
3. Stage A (`opmin optimize`) should run first: it does the mechanical rewrites (fully qualified
   calls, repeated reads) safely and in bulk. This stage is for what rules cannot do.

## 1. Start a session

```bash
opmin llm:targets [paths...] --format=json
```

It prints the run directory (`run`), `attempts_per_function` and the targets: top-level functions
with the most opcodes, their closures included. Functions the user excluded, main code and
functions that are never changed (`eval`, `include`, line numbers) are not targets.

## 2. For each target, most opcodes first

1. Read the context:

   ```bash
   opmin llm:context 'App\Service\Pricing::total'
   ```

   It shows the source, the optimized opcode listing and histogram, the dynamic constructs with
   the rules they impose (**follow them literally**), the acceptance thresholds and the attempts
   rejected so far with their reasons — never repeat a rejected idea.

2. Write the new version of **only this function** to `<run>/candidate.php`: the whole function
   from its first line (attributes, modifiers, `function`, signature) to its closing brace, with
   the indentation of the file. Include the docblock only when it must change; without one the
   original docblock stays. Nothing outside the function can change: no `use function` imports, no
   new constants, methods or properties — `apply-candidate` refuses such a candidate.

3. Apply it:

   ```bash
   opmin apply-candidate 'App\Service\Pricing::total' <run>/candidate.php --format=json
   ```

   - exit 0 — accepted and committed: `attempt.gain` opcodes saved. Go to the next target.
   - exit 1 — rejected: `attempt.reason` says why (opcodes did not drop, readability, signature,
     PHPStan, tests, or a `counterexample` — the input on which your version behaves differently).
     Try a different idea while `attempts_left > 0`, or move on.
   - exit 2 — nothing was tried (no attempts left, dirty tree, not a target): move on or report.

   A function where nothing helps is a normal outcome: say so, do not force it.

## 3. Finish

```bash
opmin llm:finish
```

It runs the project's full test suite (each attempt ran only the tests of its function), takes
back accepted candidates from the last one while it fails, and writes `report.json` and
`opmin.patch` in the run directory. Report to the user: functions changed, opcodes saved, what
was rejected and why.

## Hard rules

- Do not change the signature: parameter names, types, defaults, by-reference markers, the return
  type, visibility, `static`, attributes.
- Keep every side effect, in the same order and number: calls that do I/O, write properties or
  globals, throw, print, log, read time, randomness or the environment.
- Keep the order in which arguments and operands are evaluated when any of them has an effect.
- Keep the exceptions: which, when, with which message. Do not add or remove `try`/`catch`.
- Keep the warnings and deprecations: `$a['k'] ?? null` instead of `$a['k']` removes an
  "Undefined array key" warning, and frameworks turn warnings into exceptions.
- Do not remove type checks the behavior depends on (`is_*`, `instanceof`, casts), even when the
  phpdoc says the type is guaranteed: the caller may pass anything.
- No nested ternaries, assignments in conditions, `goto` (see the context for the list in force).
  Cyclomatic complexity and nesting must not grow.
- The gain must be worth the diff: at least `min_gain` opcodes and `min_gain_per_line` opcodes per
  changed line. Rewriting a whole function for one opcode is rejected.

## Patterns

Measured with `bin/bench --only=patterns` on PHP 8.1–8.5 (`ops_opt` of a method body; a range is
8.1–8.3 / 8.4–8.5). Each is a hypothesis — `apply-candidate` counts the real effect in place. Check the
semantic condition of each one before you use it.

| Pattern | Opcodes | Condition |
|---|---|---|
| Fully qualified global function in a namespace: `strlen()` → `\strlen()` | −2 … −7 | The namespace does not define a function of that name. Stage A usually did this already |
| `for ($i = 0; $i < count($a); $i++)` over `$a[$i]` → `foreach ($a as $x)` | −3 | `$a` is a list (keys 0…n−1), the loop does not change `$a` or `$i` |
| `sprintf('%s-%s', $a, $b)` → `$a . '-' . $b` | −3 / −1 | Only `%s` with string or int operands (float formatting differs) |
| `if ($c) { return true; } return false;` → `return $c;` | −2 | `$c` is already a bool (otherwise `return (bool) $c`, which may not save anything) |
| `array_push($a, $v)` → `$a[] = $v` | −2 | One value pushed, the result of `array_push` is not used |
| `substr($s, 0, 3) === 'abc'` → `str_starts_with($s, 'abc')` | −2 | PHP 8.0+, `$s` is a string |
| `isset($a['k']) ? $a['k'] : $d` → `$a['k'] ?? $d` | −1 | Always the same |
| `"a{$v}b"` (interpolation) → `'a' . $v . 'b'` | −1 | Always the same; the opposite direction costs +1 |
| `strpos($s, $n) !== false` → `str_contains($s, $n)` | −1 | PHP 8.0+, string operands |
| `count($a) === 0` → `$a === []` | −1 | `$a` is always an array, never a `Countable` object |
| `strlen($s) === 0` → `$s === ''` | −1 | `$s` is always a string |
| `$v = $v + 1` → `++$v` | −1 | `$v` is an int or a float: `++` on a string is alphanumeric |
| A temporary used once, holding a call result: `$t = trim($v); return strtolower($t);` → nested call | −1 / 0 | The temporary is not named in `compact()`, `get_defined_vars()`, etc. (see the restrictions) |

Usually **no gain** — do not rewrite for these alone:

- `fn` → `static fn`, `==` → `===`, `is_null($v)` → `$v === null`, `intval($v)` → `(int) $v`,
  `array_key_exists()` → `isset()` (fully qualified, both compile to one opcode);
- early return instead of `if/else`, removing `else` after `return`, `if/return` → ternary,
  nested `if` → `&&`;
- a temporary holding an expression (not a call), a constant expression in a variable (the
  optimizer folds both);
- moving a loop invariant out of a loop: the static count stays (fewer opcodes are *executed*,
  but opmin counts compiled opcodes, so the candidate is rejected unless something else drops);
- `in_array($v, ['a', 'b'], true)` → `$v === 'a' || $v === 'b'` costs +2.

## Reading the listing

- `INIT_NS_FCALL_BY_NAME` + `JMP_FRAMELESS` + a second call path: an unqualified function in a
  namespace — the compiler does not know which function runs. A leading `\` removes both paths.
- `INIT_FCALL` / `DO_ICALL` vs a dedicated opcode (`STRLEN`, `COUNT`, `TYPE_CHECK`,
  `ARRAY_KEY_EXISTS`, `IN_ARRAY`, `FRAMELESS_ICALL_*` on 8.4+): dedicated ones are cheaper.
- `QM_ASSIGN` to a `CV` that is read once: a temporary the optimizer kept.
- `ROPE_INIT` / `ROPE_ADD` / `ROPE_END`: string interpolation.
- `JMPZ`/`JMPNZ`/`JMP` chains: conditions; fewer branches usually mean fewer jumps.
- `VERIFY_RETURN_TYPE`, `RECV`: from the signature — never yours to change.
