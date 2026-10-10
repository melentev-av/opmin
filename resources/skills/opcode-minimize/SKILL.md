---
name: opcode-minimize
description: Minimize PHP opcodes with opmin — rewrite functions one at a time so they compile to fewer opcodes without changing behavior, with opmin counting and verifying every candidate. Use when the user asks to minimize PHP opcodes, to run the LLM stage of opmin, or to micro-optimize PHP functions with opmin.
---

<!-- opmin-skill-version: {{version}} -->

# Minimize opcodes with opmin (Stage B)

You propose rewritten functions; `opmin` decides. A candidate is kept only when it has strictly fewer
opcodes, passes the readability and signature rules and proves the same behavior (PHPStan, the
project's tests, differential tests against the original). Never claim a gain that `apply-candidate`
did not report.

## 0. Before you start

1. `opmin --version` must print `{{version}}` — the version this skill is written for. If it differs,
   or the version here is still a placeholder in double braces (a skill installer copied this file
   from the opmin package as is), run `opmin skill:update` (add `--global` if the skill is installed
   in `~/.claude/skills`) and reload the skill.
2. Work on a branch of this run: every accepted candidate becomes a commit of its file. On the
   default branch or a branch shared with other work, create one for the run
   (`git switch -c opmin/<short-name>`). The files you will optimize must have no uncommitted
   changes (other files may stay dirty), or the user's edits land in your commits: check
   `git status` and, if they do, ask the user to commit or stash them and wait for them; do not do
   it yourself.
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
   the rules they impose (**follow them literally**), the acceptance rules and the attempts
   rejected so far with their reasons — never repeat a rejected idea.

2. Pick the rewrite. Before your first candidate, read [PATTERNS.md](PATTERNS.md) next to this
   file: the measured rewrites with the condition each one needs, the ones that usually save
   nothing, and what the opcodes in the listing point to.

3. Write the new version of **only this function** to `<run>/candidate.php`: the whole function
   from its first line (attributes, modifiers, `function`, signature) to its closing brace, with
   the indentation of the file. Include the docblock only when it must change; without one the
   original docblock stays. Nothing outside the function can change: no `use function` imports, no
   new constants, methods or properties — `apply-candidate` refuses such a candidate.

4. Apply it:

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

- The signature stays identical: parameter names, types, defaults, by-reference markers, the
  return type, visibility, `static`, attributes.
- Side effects keep their order and count: calls that do I/O, write properties or globals, throw,
  print, log, read time, randomness or the environment.
- Arguments and operands keep their evaluation order whenever any of them has an effect.
- Exceptions stay the same — which, when, with which message — and so do the `try`/`catch` blocks.
- Warnings and deprecations stay: `$a['k'] ?? null` in place of `$a['k']` silences an "Undefined
  array key" warning, and frameworks turn warnings into exceptions.
- Type checks the behavior depends on stay (`is_*`, `instanceof`, casts), even when the phpdoc
  says the type is guaranteed: the caller may pass anything.
- The candidate meets the **Acceptance rules** of `llm:context` — the minimum gain in total and per
  changed line, the allowed growth of complexity and nesting, the forbidden constructs. They are
  the ones in force for this project; a whole-function rewrite for one opcode falls below them.
