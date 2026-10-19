# opmin

**Minimizes PHP opcodes without changing behavior.**

opmin counts opcodes of every function, method and closure (after the OPcache optimizer), rewrites code with
Rector rules and an LLM stage, and keeps a change only when the opcode count went down **and** the behavior is
proven unchanged: syntax and PHPStan, the project's own tests, and differential testing of the old and the new
version of each function on generated inputs (return values, exceptions, output, warnings, by-ref arguments,
object state, calls to collaborators).

As a CI guard, `opmin check` fails a pull request when functions grow in opcodes compared to a committed baseline.

Which rules run by default is decided by their execution cost, not only by opcodes: every rule is measured in
machine instructions per call before and after, on PHP 8.1–8.5 with and without the JIT
([docs/rule-cost.md](docs/rule-cost.md)), and rules that save opcodes but cost more are off.

> **Status: 0.x.** Counting, verification, both optimization stages, the report, the CI guard and delivery
> (static binary, PHAR, Docker image, `doctor`, `self-update`, git packages) work. Formats and options may still
> change before 1.0.

## Installation

opmin needs **two PHPs**, and the binary brings one of them:

- the PHP that *runs* opmin — embedded in the static binary (or your PHP 8.3+ for the PHAR);
- `php.binary` — **the PHP of your production** (8.1–8.5, with OPcache). It compiles the code for counting, runs
  the differential tests and your tests, so its version must be the one you deploy. `opmin doctor` checks it first.

**1. Static binary (recommended)** — Linux x86_64/aarch64 (static, any distribution), macOS x86_64/arm64:

```bash
curl -fsSL https://raw.githubusercontent.com/melentev-av/opmin/master/install.sh | sh
curl -fsSL https://raw.githubusercontent.com/melentev-av/opmin/master/install.sh | sh -s -- --dir=/usr/local/bin --version=<version>
opmin self-update            # the latest release; --check only tells; --to=<version> installs that one
```

`install.sh` puts `opmin` into `~/.local/bin` (or `--dir`), checks the SHA-256 against `sha256sum.txt` of the
release and, with `openssl`, the signature of `sha256sum.txt`. `self-update` always checks both and starts the new
binary once before replacing the old one. Archives are also on
[GitHub Releases](https://github.com/melentev-av/opmin/releases) — see «Verifying a download» below.

**2. PHAR** — the same scoped build for your own PHP 8.3+: `opmin.phar` on the release page.

**Composer** — `composer require --dev melentev-av/opmin` installs the sources with their dependencies (Rector
2.7, nikic/php-parser 5, Symfony Console/Process/Yaml 7.3+ or 8, PHPStan phpdoc-parser) into your `vendor/`. If
they conflict with the project (e.g. it is on Rector 1 or php-parser 4), use the PHAR or the static binary.

**3. Docker** — `ghcr.io/melentev-av/opmin:<version>-php<8.1…8.5>`: the binary plus the PHP of production with
OPcache (JIT off), pcov, git and composer. Pick the tag of your production PHP; the images are meant for CI and
for git packages. The floating `php<8.1…8.5>` tag follows the latest release.

```bash
docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:<version>-php8.3 opmin doctor
```

**Pinning the version per project.** Commit a `.opmin-version` (an exact version `x.y.z`, or a constraint like
`^x.y`; for 0.x `^0.4` means `>=0.4.0 <0.5.0`) or set `requires:` the same way in `opmin.yaml`: another opmin
stops with exit code 2 and the `opmin self-update --to=` command to run. A global `opmin` started inside a
project that has its own — `vendor/bin/opmin` (installed by composer) or an `opmin` binary in the project root
(e.g. downloaded by [dload](https://github.com/php-internal/dload)) — runs that one instead
(`OPMIN_NO_DELEGATE=1` keeps the global one).

**From sources** (development): `git clone git@github.com:melentev-av/opmin.git && cd opmin && composer install &&
bin/opmin list`.

### Verifying a download

Every release has `sha256sum.txt`, its ECDSA P-256 signature `sha256sum.txt.sig` and GitHub build provenance
attestations. The public keys are [resources/release-keys](resources/release-keys) (a primary one and a reserve
one kept offline):

```bash
sha256sum --check --ignore-missing sha256sum.txt
openssl dgst -sha256 -verify primary.pub.pem -signature sha256sum.txt.sig sha256sum.txt
gh attestation verify opmin-<version>-linux-x86_64.tar.gz --repo melentev-av/opmin
```

## Quick start

```bash
opmin init        # opmin.yaml: every key with a comment; php.target from php.binary, paths, test runner, formatter, PHPStan;
                  # ends with the next commands; on a terminal offers to run doctor (--doctor / --no-doctor)
opmin doctor      # php.binary first, then OPcache, the harness, syntax, tests, PHPStan, coverage, git — with fixes
opmin count       # opcodes per function
opmin optimize    # Rector stage, every accepted step is a separate commit
# then, in Claude Code: "minimize opcodes with opmin" — the skill opcode-minimize (LLM stage)
opmin baseline    # commit opmin.baseline.json, then `opmin check` in CI keeps opcodes from growing
```

A run ends with a summary like this, and `.opmin/report_<ts>.md` with every function:

```text
 ------ ------------------------------------ ------ ------------- --------- ----------------
  Pass   Rule                                 Kept   Rolled back   Opcodes   Commit / error
 ------ ------------------------------------ ------ ------------- --------- ----------------
  1      FullyQualifyGlobalCallsRector        14     2             -85       039d2f6a
  1      ExtractRepeatedPropertyFetchRector   2      1             -6        bc951c85
  1      ExtractRepeatedArrayDimFetchRector   1      1             -2        4d3f2f4b
 ------ ------------------------------------ ------ ------------- --------- ----------------

The full run of the project's tests passes on the result.

 [OK] 442 → 349 opcodes (-93). Report: .opmin/report_20261007-192240.md, patch: .opmin/runs/20261007-192240/opmin.patch
```

`opmin doctor` exits with 1 when something blocks `count`/`optimize` (warnings — e.g. no pcov, so every step
runs the whole test suite — do not); `--with-tests` also runs your test suite once.

## Counting opcodes

```bash
opmin count src lib/Legacy.php                      # table: function, file:line, ops_raw, ops_opt, vars, tmps
opmin count --filter='App\Service\*' --exclude=tests
opmin count --top=20                                # the 20 largest functions by ops_opt
opmin count --min=50 --sort=ops_raw                 # functions of 50+ ops_opt, largest ops_raw first
opmin count --format=json > before.json             # deterministic report, keys sorted
opmin diff before.json after.json                   # per function: fewer / more opcodes, new, removed
```

- Code is compiled by `php.binary` (your production PHP, 8.1–8.5) with OPcache, never executed and never by the
  PHP running opmin. `ops_opt` — opcodes after the optimizer — is the main metric; `ops_raw` is before it.
- The compile settings are those of production whatever your php.ini says: OPcache with every default optimizer
  pass, no JIT, no file cache or preloading, Xdebug and pcov off (pcov turns jump tables off: a `switch` of int
  labels loses its `SWITCH_LONG`), `zend.assertions=-1` (`assert()` compiles to nothing). They are part of
  `optimizer_hash`: reports and baselines taken before opmin pinned `zend.assertions` and pcov are not comparable
  with new ones — take them again.
- Every function, method, property hook (8.4+), closure and the code outside functions (`src/x.php::<main>`,
  counted but not optimized) gets a stable key: `App\Foo::bar`, `App\Foo::bar::{closure:2}`,
  `App\Foo::bar::{class:1}::baz` for anonymous classes — ordinals, not line numbers. Trait methods are counted
  once, in the trait; abstract and interface methods are not reported.
- `--sort=file|ops_opt|ops_raw` orders the table: by file and line (default), or by opcodes, most first.
  `--top=N` keeps the first N rows and sorts by `ops_opt` unless `--sort` is given; `--min=N` keeps functions
  with at least N `ops_opt`. Both leave out main code (it is not optimized) and the per-file table; the totals
  stay those of the whole report. The three shape the table only: `--format=json` is the full report `diff`
  reads, so with them it exits with code 2 — cut the JSON with `jq` instead.
- Counts depend on the PHP version: reports carry `php` and `optimizer_hash`, and `diff` refuses to compare
  reports taken with different ones.
- Counts are cached in `.opmin/cache/` by file content, PHP version, optimizer settings and opmin version:
  re-counting an unchanged project does not start PHP for compilation at all. A relative `cache.dir` is next to
  the config in use, so in a monorepo the packages share the root's cache.
  `cache.driver` picks the storage: `auto` (default) is one SQLite database `.opmin/cache/cache.sqlite` when the
  PHP running opmin has `pdo_sqlite` (the static binary and most PHP builds do) and a JSON file per entry
  otherwise; `sqlite` insists on the database (a config error, exit code 2, without `pdo_sqlite`); `files` is
  always a file per entry; `memory` keeps counts and references for one run only, nothing on disk. Both disk
  stores are shared by parallel runs; a corrupt or locked database only costs the cache, never the run
  (`opmin doctor` names it — delete the file and the next run creates it anew, or set
  `cache.recreate_corrupt: true` to let runs do it).

## Verifying behavior

```bash
opmin verify src/Cart.php /tmp/Cart.php                 # differential tests of every changed function
opmin verify src/Cart.php /tmp/Cart.php --with-tests    # + php -l, PHPStan "no new errors", the project's tests
```

- Every changed function is called in two harness workers under `php.binary` — one with the original file, one
  with the candidate — on the same inputs: boundary values of its parameter types, values a non-strict caller
  may pass, literals of both versions, random inputs and coverage-guided mutations. Return values, exceptions,
  output, warnings (also thrown as `ErrorException`), by-ref arguments, `$this`, mock calls, globals and statics
  are compared. Time and randomness in the function's namespace are faked; a function that disagrees with
  itself is nondeterministic and stays unverified.
- Each call is limited by `verification.call_timeout_ms` (1000 ms); an input the original hangs on is dropped,
  and after 5 such inputs in a row the function stays unverified ("the original times out on generated
  inputs"). An input on which the original runs out of `verification.memory_limit` (and the candidate does the
  same) is dropped and counted in that row too ("the original runs out of memory on generated inputs").
  The whole test of one function is limited by `verification.time_budget_ms` (15 s; a typical function takes
  0.1–3.5 s): when it runs out, the inputs checked so far decide through the coverage threshold below, as
  with the search limit `verification.fuzz_time_ms`. The shrinking of a difference is never cut short.
- A difference is shrunk to a minimal input and saved to `.opmin/runs/<timestamp>/counterexamples/` as JSON and as a
  test for the project's runner (PHPUnit, Pest, Testo; a plain script otherwise).
- Accepted: proven by differential tests with `verification.min_branch_coverage` of the original's branches,
  or unverified but executed by passing project tests, or with `verification.allow_unverified`. Branches the
  project's PHPStan proves dead (`--with-tests`: always-true or impossible conditions, unreachable code) are
  not counted. Functions with I/O are never called with generated inputs: only the project's tests prove them.
  A call through a variable or a callback counts as I/O when the function names an I/O function in a string
  (`$f = 'shell_exec'; $f(...)`), and the harness itself cannot start processes or send mail (`exec`,
  `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `pcntl_exec`, `mail` are disabled): a function that
  still reaches one stays unverified.
- The differential tests of one step run `verification.jobs` at a time, each in a process of its own
  (`--jobs=N` for one run). `0` (auto, the default) is half of the CPU cores — a test keeps two harness workers
  busy — at most 4, within the free memory (2 × `verification.memory_limit` per test); `1` runs them one after
  another in the opmin process. The inputs do not depend on it (`verification.seed`), but the time limits
  (`call_timeout_ms`, `fuzz_time_ms`, `time_budget_ms`) do: for verdicts reproducible across machines, e.g. in
  CI, use `--jobs=1`. `--guard-perf` times the kept changes afterwards, one by one.
- Flags of `opmin count` (`compact`, `static_var`, `eval`, `io`…) say which constructs restrict a function.

## Optimizing (Stage A: Rector)

```bash
opmin optimize                         # the config `paths` (src/ or app/)
opmin optimize src/Cart.php 'src/**/*Service.php' --dry-run
opmin optimize --with-standard-rector  # also the standard Rector rules (off by default)
opmin optimize --review                # confirm every change: y / n / a (all of this rule) / q
opmin optimize --resume                # continue the latest interrupted run (or --resume=.opmin/runs/<ts>)
opmin optimize --guard-perf            # also roll back changes that make a function slower
opmin optimize --guard-cost            # also roll back changes that cost more CPU instructions per call (callgrind)
opmin optimize --rector-rule=fqn --rector-rule=count   # only these rules: alias, short or full class name
opmin optimize --plan                  # say what the run would use and exit: nothing is changed or tested
```

- `--rector-rule` takes the alias of a rule (see [Excluding code](#excluding-code)), the short class name of a
  rule of the run (`HoistLoopInvariantCountRector`) or the full class name of any installed rule; an unknown name
  is an error with the closest one (``Unknown Rector rule `fqm` in --rector-rule. Did you mean `fqn`? …``).

- Every run starts with a header on stderr: the files and how many functions they have (the counts come from the
  cache), the rules, the test runner, whether `php.binary` has a coverage driver, PHPStan, the formatter and the
  differential tests at a time (`verification.jobs` resolved). When the runner is a plain `tests.command` or
  there is neither pcov nor Xdebug, a warning says at once that every step runs the whole test suite.
  `--plan` prints the header and exits with 0: no tests run, no files change, no run directory is written.

  ```
  Optimizing 12 file(s) of /app with 11 rule(s), PHP 8.4.1 (target 8.1), git: a commit per accepted step.
    Functions: 230, 214 of them optimizable
    Rules: FullyQualifyGlobalCallsRector, ExtractRepeatedPropertyFetchRector, ExtractRepeatedArrayDimFetchRector, HoistLoopInvariantCountRector, RemoveUnusedClosureUseRector, SprintfToConcatRector, ConcatToInterpolationRector, ArrayPushToAssignRector, … 3 more
    Tests: phpunit, the tests that cover a changed function
    Coverage driver: yes (pcov or Xdebug in php.binary)
    PHPStan: vendor/bin/phpstan
    Formatter: vendor/bin/php-cs-fixer fix --quiet --path-mode=intersection {files} (detected)
    Differential tests: 4 at a time (verification.jobs: 0, auto)
  ```
- Rules are applied one by one, in passes until a pass changes nothing (`rector.max_passes`). After each rule:
  the project's formatter on the changed files (Pint, PHP-CS-Fixer, ECS, PHPCBF — detected, or `commands.format`,
  `--format=none`), `php -l`, a count. A changed function is kept only when it saves opcodes, the gain is worth
  the changed lines (`readability.*`), complexity and nesting do not grow and its signature stays as
  `signatures.*` allow; then it is verified on all three levels. Everything else is taken back.
- In a git working tree every accepted step is a commit of its files; outside git the originals are copied to
  `.opmin/runs/<ts>/original/`; `--dry-run` restores everything. Each run writes the report (below), the counts before
  and after and `opmin.patch`, and ends with a full run of the project's tests.
- Under git opmin does not check the tree by default: its commits and rollbacks name their paths, so other dirty
  files stay as they are, but your own uncommitted edits of a file the run changes go into opmin's commit.
  `--with-git` (or `git.require_clean: targets`) requires the files the run may change to be committed, and
  `git.require_clean: all` asks for a clean tree.
- `--review` shows each change that passed every check — the diff of the function, `−N opcodes, rule X, checks:
  diff-tested 96% (210 inputs) ✓` — and asks `y` (apply), `n` (roll back and never propose again), `a` (apply this
  and the rest of the rule), `q` (roll back and end the run). Declined changes go to `opmin.baseline.yaml`
  (`rejected: [{function, rule}]`, committed at the end of the run; remove an entry to allow it again) and are
  skipped by both stages. Without an interactive session (`-n`, CI, an agent) every change that passes is applied.
- `--guard-perf` (or `guard_perf.enabled`) times every diff-tested change: both versions on up to 20 of the inputs
  of its differential test, compiled by OPcache as in production, in alternating order over 7 rounds; the median
  change of the time goes to the report, and a change slower by more than `guard_perf.max_regression_percent`
  (5) is rolled back even with fewer opcodes. Changes proven only by the project's tests are not timed.
- `--guard-cost` (or `guard_cost.enabled`) counts the CPU instructions per call of every diff-tested change under
  callgrind, without the JIT and with the tracing JIT: both versions on up to `guard_cost.inputs` (8) of the inputs
  of its differential test, each input in a process of its own (a fork of the loaded worker), the harness's own call
  subtracted. Counts are deterministic, so a few percent on a small function show. A change is rolled back when, in
  either mode, the mean over the inputs grows by more than both `guard_cost.max_regression_percent` (5) and
  `guard_cost.max_regression_instructions` (15). The mean, not the worst input: generated inputs favor boundary
  values — an interpolated string costs more than `.` when its parts are empty — which production sees far less. It needs valgrind: on Linux it is `valgrind`; elsewhere set
  `guard_cost.valgrind` to a wrapper that runs valgrind where php.binary runs and `guard_cost.php` to the PHP it
  starts there (`php` in the container). It is slow: about 10–30 s per kept change.
- Ctrl+C (SIGINT, SIGTERM) stops a run: the step under way is dropped — its tools got the signal too — and the
  report says the run was interrupted. Every step is saved to `.opmin/runs/<ts>/state.json`; `--resume` puts the files
  back to the last accepted step (also after a crash or `kill -9`, dropping an opmin commit the state missed) and
  continues with the same files, rules and options, ending as an uninterrupted run would.
- The progress goes to stderr, the summary to stdout. In a terminal it is one line redrawn in place — pass, rule,
  phase, `verifying 12/47` (functions of the round whose differential test is done), `round 2` after a take-back,
  the current function, the time since the start — with the result of every step and the warnings above it.
  Without a terminal (CI, a pipe, `--no-ansi`) it is a log: every line with the time and the seconds since the
  start (`[14:02:11 +83s] Pass 1: FullyQualifyGlobalCallsRector`), a result line per step (`kept 4, rolled back 1,
  -12 opcodes (51s)`), the tests on the original code and the coverage map. `-v` adds the phases inside a
  verification: PHPStan, the project's tests, each differential test. A missing coverage map is a warning at the
  first verification: every step then runs the whole test suite.
- A change rolled back for a reason of its own (no gain, a difference, not proven…) is not tried again in later
  passes while the function stays the same.
- opmin's own rules prove their safety conditions themselves (native types through PHPStan). On by default:
  `FullyQualifyGlobalCallsRector` (`strlen()` → `\strlen()`, only when no namespaced function or test mock —
  php-mock, ClockMock — can shadow it), `ExtractRepeatedPropertyFetchRector` (readonly properties across calls,
  mutable ones while no user code runs, no `__get`/hooks), `ExtractRepeatedArrayDimFetchRector` (only keys proven to
  exist), `HoistLoopInvariantCountRector` (`\count()` of an unchanged local array out of a `for` condition),
  `RemoveUnusedClosureUseRector` (a by-value `use` of a scalar or array variable the closure never names),
  `OrIdenticalToInArrayRector` (`$s === 'a' || $s === 'b'` → `\in_array($s, ['a', 'b'], true)`: strict comparisons of
  one variable, property or element read without side effects with string and integer literals, `min_items`: 2),
  and `IfChainToMatchRector` (`if ($s === 'a') return …; if ($s === 'b') return …; return …;` → `return match`, string
  labels only: with int labels `match` costs more than the chain). Off by default, as they are not cheaper by
  execution cost on PHP 8.1–8.5: `NullCheckTernaryToNullsafeRector` (`$x !== null ? $x->a() : null` → `$x?->a()`,
  +20…+22 % with the tracing JIT, +61 % on `null`) and `SwitchToMatchRector` (a `switch` of `return`s → `return
  match`, +11 % without JIT, +27…+29 % with it); enable one with its options instead of `false` in
  `rector.custom_rules`. Also off by default, a trade of readability for speed without JIT:
  `MatchTrueToIfRector` (`return match (true) { $n < 0 => 'neg', default => 'pos' };` → `if ($n < 0) { return
  'neg'; } return 'pos';`, or an `if`/`elseif`/`else` of assignments to one local variable: conditions of native
  type `bool`, one per arm, a `default` arm), −24…−29 % without JIT and −14…−16 % with it; the `if` is a level
  deeper, which the rule allows over `readability.max_nesting_increase`. For the two extraction rules, a call of a pure built-in function (`\explode()`,
  `\strlen()`, `\htmlspecialchars()`…: side-effect free by PHPStan's function metadata, of an always compiled-in
  extension, minus a denylist of nondeterministic ones) with arguments of scalar native types runs no user code
  (`Opmin\Rector\Support\PureFunctionCall`).
- Built-in function calls that have a cheaper form (opmin's rules too, proven by native types):
  - `SprintfToConcatRector`: `\sprintf('%s-%d', $s, $i)` → `$s . '-' . $i` — a literal format of `%s`, `%d`, `%%`
    only, as many arguments as specifiers, each a `string` or an `int` (`%d`: an `int`). Only for `php.target`
    below 8.4: there PHP compiles such a `sprintf()` into a rope itself, and the rope is cheaper than the
    concatenation (fewer opcodes, but more CPU instructions per call).
  - `ConcatToInterpolationRector`: `$dir . '/' . $name . '.php'` → `"{$dir}/{$name}.php"` — PHP builds a string
    of three and more parts as a rope, one allocation instead of one per `.`: an opcode more, but 9–12 % fewer
    CPU instructions per call on PHP 8.1–8.5 without JIT and 28–30 % fewer with it, so it is accepted by cost even
    when the count grows. Without JIT it costs more when the parts are empty strings (`.` then skips the
    allocation): `docs/rule-cost.md`. From the start of the chain up to the first operand that `{…}` cannot hold (variables,
    properties, array elements with a literal or variable key, method calls on them, string and int literals);
    the rest stays concatenated. A variable operand written by a later one (`$i . $this->f($i++)`) keeps the chain.
  - `ArrayPushToAssignRector`: `\array_push($a, $v);` → `$a[] = $v;` — one or two values, the result unused, a
    defined local `array` or a property that is surely initialized (a default value, never unset).
  - `StrStartsWithRector`: `\substr($s, 0, 3) === 'abc'`, `\strpos($s, $p) === 0` → `\str_starts_with($s, …)`
    (`!==` → `!\str_starts_with()`) — strict comparisons only, a length equal to the literal's, native strings.
- Code can be excluded from every rule or from some: see [Excluding code](#excluding-code).
- Which standard Rector rules save opcodes: [docs/standard-rules.md](docs/standard-rules.md).
- What every rule costs to execute, before and after, on PHP 8.1–8.5 with and without JIT: [docs/rule-cost.md](docs/rule-cost.md)
  (`bin/bench --cost`).

## Optimizing (Stage B: LLM, a Claude Code skill)

What rules cannot do, a model proposes and opmin judges — one function at a time, through the same checks as a
Rector step. `opmin init` puts the skill `opcode-minimize` into `.claude/skills/` (`--no-skill` to skip);
`opmin skill:install --global` puts it into `~/.claude/skills/`, `opmin skill:update` brings it to the version of
the installed opmin. The skill drives these commands:

```bash
opmin llm:targets src --format=json            # starts a session in .opmin/runs/<ts>/: the llm.top_n functions with the most opcodes
opmin llm:context 'App\Cart::total'            # source, opcode listing, histogram, dynamic constructs with their rules,
                                               # readability and signature rules, rejected attempts with their reasons
opmin apply-candidate 'App\Cart::total' .opmin/runs/<ts>/candidate.php --next --format=json
opmin llm:finish                               # the full test run (taking back attempts while it fails), report, patch
```

- `apply-candidate --next` adds `next` to the result, so a target costs one `llm:context` call at most: `retry`
  the same function (a rejection with attempts left; the reason is in `attempt`), `rewrite` the next unfinished
  target in the session order with its `context` (as `llm:context --format=json` prints it), or `finish`. The
  candidate can come on stdin (`-`) instead of a file.

- A candidate is the new source of **only that function**; anything else changed (another function, an import) is
  refused. It is kept only with strictly fewer opcodes, within `readability.*` and `signatures.*`, and proven by
  PHPStan, the project's tests and differential tests; then it is a commit. A rejection names the reason — with
  the shrunk counterexample input when behavior differs — and goes into the context of the next attempt.
- At most `llm.attempts_per_function` attempts per function. `#[\Opmin\Ignore(rules: ['llm'])]` /
  `@opmin-ignore llm` keeps a function away from this stage only.
- The patterns the skill knows are measured on PHP 8.1–8.5 (`bin/bench --only=patterns`), including the ones that
  usually give nothing: [resources/skills/opcode-minimize/PATTERNS.md](resources/skills/opcode-minimize/PATTERNS.md).

## The report

Every run (`optimize`, `llm:finish`) writes `.opmin/runs/<ts>/report.md` for people — and the same file as
`.opmin/report_<ts>.md`, next to the reports of the earlier runs — and `.opmin/runs/<ts>/report.json` for tools:

- the total: opcodes before → after, %; whether the full run of the project's tests passes;
- what to do next (`advice` in `report.json`, at the top of `report.md`, and at the end of the console output
  next to the functions that saved most): keyed on the cause of the rolled-back changes and on what the run had
  to prove them with — set `tests.runner`; enable pcov or Xdebug in `php.binary` when the runner gives no
  coverage map; fix the autoload (`composer install`, then `opmin doctor`) when the harness could not load the
  code; add tests for functions with side effects; the counterexamples of a difference; the `signatures.*`
  settings. Expected rollbacks (no gain, readability, excluded, declined in the review) are only counted.
  `--allow-unverified` is never the advice: it comes last, with a warning;
- every changed function: before → after, what saved the opcodes (rule or LLM), how it is proven (`diff-tested`,
  `tests`, `unverified`), the branch coverage of the differential tests, the project's tests that run it, the flags
  of dynamic constructs; changes that save executed opcodes rather than static ones are listed apart, and so are
  changes of rules measured cheaper to run (`docs/rule-cost.md`), which may add an opcode or two;
- every rolled-back change grouped by reason (a difference with its counterexample input, failing tests, PHPStan,
  not proven, signature, dynamic constructs, readability, no gain, excluded); in `report.json` each one has a
  `kind` (the group) and a machine-readable `cause` — what to fix first so that it could be kept, e.g.
  `not_proven.no_runner`, `not_proven.no_coverage_map`, `not_proven.harness_load`, `not_proven.side_effects`,
  `not_proven.no_inputs`, `diff_test`, `signature.public_api`;
- counterexamples as tests of the project's runner in `.opmin/runs/<ts>/counterexamples/`;
- the environment: PHP runtime, `php.target`, the optimizer settings, opmin, Rector, the project's PHPStan. A run
  warns when one of them changed since the previous run, `opmin diff` when the reports come from other opmin
  versions or targets;
- where the time went (`report.json`, `timings`): seconds and calls per level — Rector, the formatter, `php -l`,
  counting, PHPStan before and after, the project's tests, the differential tests (worker start, planned inputs,
  fuzzing, guard-perf), commits — with the once-per-run work (`once.*`: the tests on the original code, the
  coverage map, the final test run) apart, and counters of steps, verification rounds, differential tests cut
  by `verification.time_budget_ms` (`time_budget_spent`) and stopped by timeouts of the original
  (`original_timeouts_stop`) or by running out of memory (`original_out_of_memory_stop`); `-v` prints the same
  at the end of the run. Every check of a function also has its `seconds`.

`report.json` has `"schema": 1`; its fields change only in a major release (new fields may be added).

## Runs: `runs`, `runs:show`, `runs:clean`

Every `optimize`, LLM session (`llm:targets` … `llm:finish`) and `verify` with a counterexample leaves a
directory in `.opmin/runs/<ts>/` (`-2`, `-3`… for runs of the same second and for the runs of a git package
copied from its clone). They are kept until you delete them:

```bash
opmin runs                          # id, kind, state, opcodes before → after, size; newest first
opmin runs --format=json            # {"runs": [{id, kind, state, ops_before, ops_after, size, report, backup, path}]}
opmin runs:show                     # report.md of the newest run that has a report, to stdout
opmin runs:show 20261011-062031 --format=json   # report.json as it is; --format=path prints where report.md is
opmin runs:clean --keep=5 --dry-run # what would be deleted
opmin runs:clean --keep=5           # delete it, with the copies .opmin/report_<ts>.md
```

- kinds: `optimize` (`state.json`), `llm` (`llm.json`), `verify` (only `counterexamples/`), `package` (copied
  from the clone of `optimize <git-url>`), `broken` (no state and no report: a run that failed before it saved
  anything); states: `finished`, `interrupted` (an optimize run `--resume=<dir>` continues), `open` (an LLM
  session not finished yet), `broken`;
- `runs:show` never opens a viewer; exit code 2 when there is no such run or it has no report (an interrupted, a
  verify or a broken run);
- `runs:clean` keeps the `--keep` newest finished runs, **every** interrupted optimize run and every open LLM
  session (any of them can still be continued), and a broken run written in the last hour (it may be one that
  is still starting). Outside git the `original/` of a run that changed the code (it has a patch, or it did not
  finish) and was not a dry run is the only copy of the code before the run: such runs are listed and kept
  unless `--include-backups`. The command asks nothing and prints
  what it deletes; exit code 2 for a missing or negative `--keep`;
- `runs.keep: N` in `opmin.yaml` (default `null`, off) prunes with the same rules (never the backups) every time
  `optimize` or `llm:targets` creates a run; a failure to delete is a warning and never fails the run.

## CI guard: `baseline` and `check`

```bash
opmin baseline                              # writes opmin.baseline.json — commit it
opmin check                                 # functions of files changed since check.base_ref (origin/main)
opmin check --base=origin/develop --format=github
opmin check --all                           # the whole project
opmin check --update-baseline               # write decreased, new and removed functions to the baseline
opmin check --suggest                       # for grown functions: which Rector rule would take the opcodes back
```

- `opmin.baseline.json` holds `ops_opt` per function key (`App\Foo::bar`, `App\Foo::bar::{closure:2}` — ordinals,
  not lines) with its file, plus `php` and `optimizer_hash`. Keys are sorted and there are no lines, so its diff
  in a PR shows only functions whose counts changed. Code outside functions (`<main>`) is not guarded. Not to be
  confused with `opmin.baseline.yaml` — the changes you declined in `optimize --review`.
- The changed files are `git diff --name-only <base>` (committed and uncommitted) plus untracked files; a rename
  is a removal and an addition, so a function moved to another file keeps its key and a renamed one is reported
  as removed + new.
- A function that grew by more than `check.tolerance` fails the check; a decrease, a new function and a removed
  one ask you to update the baseline (`--update-baseline` does it, keeping grown functions at their old counts —
  accepting growth is an explicit `opmin baseline`); a new function above `check.max_ops_new_function` is a
  warning. Rules measured cheaper to run may add opcodes: `opmin optimize` raises the entries of the functions they
  changed in `opmin.baseline.json` by exactly that growth (a commit of its own in git), so the check passes them and
  still fails on any other growth. `--suggest` applies every Stage A rule alone to temporary copies (the project is not touched) and
  names the one that saves the most — unverified: `opmin optimize` proves the change.
- Exit codes: `0` — nothing grew; `1` — opcodes grew (or a checked file cannot be compiled); `2` — no baseline,
  a baseline taken with another PHP minor version or optimizer settings (nothing is compared: rebuild it with
  `opmin baseline` on the PHP of the check), an unknown base ref, or invalid config.
- Formats: `table` (default), `json` (every finding, totals), `github` (workflow annotations on the PR lines),
  `gitlab` (Code Quality report), `checkstyle`. The report goes to stdout, messages to stderr.

```yaml
check:
  tolerance: 0                 # by how many opcodes a function may grow
  max_ops_new_function: null   # limit for new functions, null — no limit
  base_ref: origin/main        # base of the changed-files mode (--base=)
```

**Which image.** The check runs in `ghcr.io/melentev-av/opmin:<version>-php<X.Y>`, `<X.Y>` being the PHP minor
of your production — ask production itself, not `composer.json` (`"php": "^8.1"` says nothing about the PHP
that runs the code):

```bash
php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'   # on production: e.g. 8.3
```

- Pin the opmin version: `:0.4.0-php8.3`, not the floating `:php8.3`. The floating tag follows the latest
  release, and a new opmin may count some functions differently (a counting fix) — the check of an unchanged
  PR would then fail or ask to update the baseline. Bump the version on purpose, together with the baseline.
- The baseline must be taken with the same image as the check: the same PHP minor and the same optimizer
  settings (`optimizer_hash`: the OPcache INI of opmin and the loaded Zend extensions); another one is
  rejected with exit code 2. Create it with that image and commit `opmin.baseline.json`:
  `docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:<version>-php8.3 opmin baseline`.
- `opmin init --ci=github` writes `.github/workflows/opcodes.yml` (the action below), `--ci=gitlab` writes
  `.gitlab/opmin.yml` and prints the `include:` line for `.gitlab-ci.yml` (which it never edits). The PHP is
  the minor of `php.binary` (not `php.target`: that may be the lowest `require.php` of composer.json) and the
  image is the running opmin's; it prints both and the `docker run … opmin baseline` command. An existing file
  is never overwritten (exit code 2).

GitHub Actions (`.github/workflows/opcodes.yml`):

```yaml
name: Opcodes
on: pull_request
jobs:
  opmin:
    runs-on: ubuntu-latest
    container: ghcr.io/melentev-av/opmin:<version>-php8.3   # the PHP of your production
    steps:
      - uses: actions/checkout@v5
        with:
          fetch-depth: 0            # the base branch is needed for the changed-files mode
      - run: opmin check --base="origin/${{ github.base_ref }}" --format=github
```

Or the action of this repository (`action.yml`, composite): it runs `opmin check` in the same image with
`docker run`, so it needs a Linux runner. The version of the image is the tag of the action — Dependabot
(`package-ecosystem: github-actions`) bumps it like any other `uses:`; bump the baseline with it.

```yaml
name: Opcodes
on: pull_request
jobs:
  opmin:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
        with:
          fetch-depth: 0            # the base branch is needed for the changed-files mode
      - uses: melentev-av/opmin@v<version>
        with:
          php: '8.3'                # the PHP minor of your production
```

Inputs: `php` (required), `version` (the opmin release of the image; default — the tag of the action, required
when it is used at a branch or a commit), `base` (default `origin/<base branch>` of the pull request,
`check.base_ref` outside one), `format` (default `github`), `args` (more arguments of `opmin check`, e.g.
`--suggest`).

GitLab CI (`.gitlab-ci.yml`):

```yaml
opcodes:
  stage: test
  image: ghcr.io/melentev-av/opmin:<version>-php8.3       # the PHP of your production
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
  variables:
    GIT_DEPTH: 0
  script:
    - git fetch origin "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
    - opmin check --base="origin/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME" --format=gitlab > gl-code-quality.json
  artifacts:
    when: always
    reports:
      codequality: gl-code-quality.json
```

## Excluding code

```php
#[\Opmin\Ignore]                          // the function, method or class: every rule, both stages
#[\Opmin\Ignore(rules: ['fqn', 'llm'])]   // only these rules
// @opmin-ignore                           // the same without the dependency, right before the declaration
/** @opmin-ignore fqn,count */             // or in its docblock
```

```yaml
ignore:
  paths: [src/Legacy, database/migrations]
  functions: ['App\Foo\Bar::hotPath', 'App\Utils\*']   # function keys, * matches anything
```

- The attribute is recognized by its name in the source: the class `Opmin\Ignore` need not be installed (PHP
  checks attributes only in `newInstance()`); with opmin in `require-dev` the IDE knows it.
- A mark on a class covers all its methods and closures. `vendor/` and files marked `@generated` are never touched.
- Rule names in `rules:` and `@opmin-ignore` — an alias, the short or the full class name of any rule,
  case-insensitive (`\Opmin\Rector\Rule\FullyQualifyGlobalCallsRector` counts as its short name). A name that is
  none of the names below nor a rule of the run excludes nothing — a typo would let opmin change the code —
  so `optimize` warns about it, with the closest name, in the console and in the report:

  ```text
  [WARNING] src/Cart.php:12: `@opmin-ignore` lists `fqm`, which names no known rule, so it excludes nothing.
            Did you mean `fqn`? Known names: fqn, property_fetch, array_dim_fetch, count, closure_use, sprintf,
            interpolate, array_push, str_starts_with, in_array, if_to_match, nullsafe, match_to_if,
            switch_to_match, llm, or the short class name of a rule.
  ```

  It is a warning, not an error: a mark may name a standard Rector rule that this run does not enable.

  | Alias | Rule |
  |---|---|
  | `fqn` | `FullyQualifyGlobalCallsRector` |
  | `property_fetch` | `ExtractRepeatedPropertyFetchRector` |
  | `array_dim_fetch` | `ExtractRepeatedArrayDimFetchRector` |
  | `count` | `HoistLoopInvariantCountRector` |
  | `closure_use` | `RemoveUnusedClosureUseRector` |
  | `sprintf` | `SprintfToConcatRector` |
  | `interpolate` | `ConcatToInterpolationRector` |
  | `array_push` | `ArrayPushToAssignRector` |
  | `str_starts_with` | `StrStartsWithRector` |
  | `in_array` | `OrIdenticalToInArrayRector` |
  | `nullsafe` | `NullCheckTernaryToNullsafeRector` (off by default) |
  | `if_to_match` | `IfChainToMatchRector` |
  | `match_to_if` | `MatchTrueToIfRector` (off by default) |
  | `switch_to_match` | `SwitchToMatchRector` (off by default) |
  | `llm` | Stage B: rewrites proposed by the model |
  | `simplifyifreturnboolrector`, … | a standard Rector rule by its short class name |

## Optimizing a git package

```bash
opmin optimize https://github.com/vendor/package.git --ref=v2.1.0
opmin optimize git@github.com:vendor/package.git src/Parser --ref=main --dry-run
```

`composer install` and the tests of a package are foreign code, so by default:

- the package is cloned on your machine (your git credentials) into a temporary workspace — with Docker under
  `~/.cache/opmin/packages` (the directory Docker Desktop, colima and OrbStack share), `package.workspace`
  changes it — on a branch `opmin/<timestamp>`, and removed afterwards (links inside are never followed);
- `composer install --no-scripts --no-plugins`; `--allow-scripts` runs them when the package needs them;
- opmin then optimizes the clone **inside Docker** — the image of the newest PHP the package's `require.php`
  allows (or `php.target`): no network, a read-only root file system except the workspace, the memory limit
  `package.memory` (4g) and `package.cpus`, your user. `--no-docker` runs on this machine after a confirmation
  (`--yes` in scripts), and only if `php.binary` satisfies `require.php` and has the `ext-*` the package needs;
- the public API is never changed (`signatures.public_api` is forced off): a package is called from code nobody
  can see. `--force-public-api` lifts it;
- `.opmin/runs/<ts>/` with the report and `opmin-<package>-<ref>.patch` land in the current directory: apply the patch in
  your fork with `git apply`.

`package.docker_image` (`ghcr.io/melentev-av/opmin:{version}-php{php}`) selects another image.

The package's tests must be green on the original code without network, or optimize stops before changing
anything. Exclude the tests that need network, or verify with the differential tests only:

```bash
opmin optimize https://github.com/thephpleague/csv.git --ref=9.28.0 \
  '--set=tests.command=vendor/bin/phpunit --exclude-group=network'
opmin optimize https://github.com/symfony/string.git --ref=v6.4.46 --set=tests.runner=none  # no PHPUnit of its own
```

On macOS the workspace lives on the case-insensitive file system Docker shares with its VM: a dependency whose
archive has names that differ only in case (phpstan/phpstan) cannot be installed there. Run opmin inside the image
instead, with the workspace on the file system of the container:

```bash
docker run --rm -v "$PWD:/out" ghcr.io/melentev-av/opmin:php8.4 sh -c \
  'cd /tmp && opmin optimize https://github.com/briannesbitt/Carbon.git --ref=3.14.2 --no-docker --yes -n; cp -r .opmin opmin-*.patch /out/'
```

## Results on real packages

An example: real packages optimized in git package mode with Stage A (opmin 0.4: the four own Rector rules it had
then — `FullyQualifyGlobalCalls`, the two extraction rules and `HoistLoopInvariantCount`), then their own tests
run on the patched code and stay green. Measured in CI; the run can be repeated by hand
([smoke-real-packages.yml](.github/workflows/smoke-real-packages.yml), or `tests/Smoke/run.sh <package> <php>`
locally).

| Package | PHP | Opcodes | Saved | Functions changed | Package's tests after the patch | Time |
|---|---|---|---|---|---|---|
| symfony/string 6.4.46 | 8.1 | 5 940 → 5 863 | −77 (−1.3%) | 50 | green | 2 min |
| | 8.5 | 6 358 → 6 083 | −275 (−4.3%) | 45 | green | 2 min |
| league/csv 9.28.0 | 8.1 | 16 679 → 16 605 | −74 (−0.4%) | 24 | green (without `network`) | 1.5 min |
| | 8.5 | 16 512 → 16 404 | −108 (−0.7%) | 26 | green (without `network`) | 1.5 min |
| nesbot/carbon 3.14.2 | 8.1 | 26 823 → 26 629 | −194 (−0.7%) | 53 | green (without `localization`) | 5.5 min |
| | 8.5 | 27 413 → 26 160 | −1 253 (−4.6%) | 106 | green (without `localization`) | 8 min |

`php.binary` and `php.target` are the PHP of the row; CI runners, the static binary. Most of the gain is
`FullyQualifyGlobalCallsRector`, and the gap between 8.1 and 8.5 is its: since PHP 8.4 an unqualified call of a
frameless function (`trim`, `str_replace`, `implode`…) in a namespace compiles into both the frameless call and the
fallback, and `\trim()` removes the fallback.

## Troubleshooting

Start with `opmin doctor` (`--with-tests` also runs your test suite once): it checks `php.binary`, OPcache, the
harness, the tests, PHPStan, coverage and the cache, and names a fix for each problem.

- **Every step runs the whole test suite, slowly.** `php.binary` has neither pcov nor Xdebug, so there is no
  coverage map to pick the tests that cover a changed function (the run header and `opmin doctor` warn about it).
  Install pcov into `php.binary` (`pecl install pcov`), or use the Docker image, which has it.
- **«The project's tests fail on the original code».** `optimize` stops before it changes anything: a red
  suite proves nothing. Fix the tests, exclude the failing ones (`tests.command` with PHPUnit's
  `--exclude-group`, as in [Optimizing a git package](#optimizing-a-git-package)), or set `tests.runner: none`
  to verify with the differential tests only.
- **A git package does not install on macOS.** The workspace is on the case-insensitive file system Docker shares
  with its VM; run opmin inside the image, as in [Optimizing a git package](#optimizing-a-git-package).
- **«Cannot list the internal functions of …».** `php.binary` prints warnings to stdout at startup — usually
  its ini is loaded twice (e.g. Laravel Herd, or `PHP_INI_SCAN_DIR` pointing at a directory that loads the same
  extension again: `PHP Warning: Module "…" is already loaded`). Run that PHP with `-v`: it must print no
  warnings. Remove the duplicate `extension=` line (`php --ini` lists the files), or set
  `display_errors=stderr` in that ini. opmin 0.3.1 and later name the exit code and the output.
- **`opmin check` exits with 2: «The baseline was taken with PHP X, php.binary is PHP Y».** Counts differ between
  PHP minor versions and optimizer settings, so nothing is compared. Take the baseline with the same image as the
  check (`docker run … opmin baseline`, see [CI guard](#ci-guard-baseline-and-check)) and commit it.
- **The cache is corrupt or locked.** It only costs the cache, never the run; `opmin doctor` names the file.
  Delete it (the next run creates it anew), or set `cache.recreate_corrupt: true` to let runs do it.
- **A change was rolled back as «not proven».** Nothing proved the function behaves as before: the generated
  inputs reached too little of its code, it has side effects, or no project test runs it. «What to do next» at
  the top of the report (`opmin runs:show`) says what to fix — set `tests.runner`, enable coverage, add tests.
- **``[WARNING] … `@opmin-ignore` lists `…`, which names no known rule``.** A typo in a mark excludes
  nothing, so opmin may change that code: fix the name (the warning suggests the closest one).
- **A run takes long.** `timings` in `.opmin/runs/<ts>/report.json` (`-v` prints them at the end) shows where the
  time went: the project's tests, PHPStan or the differential tests. Without a coverage map every step runs all
  tests (see above); `verification.jobs` (or `--jobs=N`) runs more differential tests at a time; `--plan` shows
  the files, rules and settings a run would use without running it.

## Configuration

`opmin.yaml` (or `opmin.yaml.dist`) in the project root; `opmin init` generates it with all keys. The commands that
analyze a project (`count`, `verify`, `optimize`, `check`, `baseline`, `llm:*`, `apply-candidate`) refuse to run
without it (exit code 2): they read their settings from it and keep the cache next to it. Everything opmin
writes goes to `.opmin/` next to the config — `.opmin/runs/<ts>/` (reports, patches, counterexamples) and
`.opmin/cache/` — and `init` or the first such command adds `.opmin/` to `.gitignore` of a git project. A git package
(`opmin optimize <git-url>`) without its own config gets an empty one beside the clone. Unknown keys and
wrong types are errors naming the key (exit code 2), with the closest key when there is one — a typo or a key in
the wrong section:

```text
Unknown config key `seed` (opmin.yaml). Did you mean `verification.seed`?
Unknown config key `verification.sed` (--set). Did you mean `verification.seed`?
```

Any key can be overridden for one run:

```bash
OPMIN_VERIFICATION_SEED=7 opmin optimize .
opmin optimize . --set=verification.seed=7 --set=rector.standard.enabled=true
```

IDE completion comes from the JSON Schema referenced in the first line of the generated file
([resources/opmin.schema.json](resources/opmin.schema.json)).

## What opmin is not

- Not a profiler and not a guarantee of speed: fewer static opcodes is not always faster code — of 19 rewrites
  measured, 11 changed opcodes and execution cost in opposite directions ([docs/rule-cost.md](docs/rule-cost.md));
  `--guard-perf` measures time where benchmarks exist.
- Not a code style tool: changes that do not reduce opcodes or cost too much readability are rejected.

## Development

```bash
composer test          # Testo: unit, integration, rector suites
composer psalm         # static analysis
composer cs:diff       # code style
make phar              # scoped PHAR in .build/phar/opmin.phar
bin/playground init    # manual end-to-end scenarios in playground/ (git-ignored)
bin/playground run --all  # every scenario: doctor, count, verify, baseline, check, optimize, tests
bin/bench              # opcodes of the rules before → after on php.binary
bin/bench --cost       # execution cost of the rules (callgrind, PHP 8.1–8.5 in Docker, JIT off and on)
```

Branches: development happens in `develop`; `master` only receives release merges. Commits follow
[Conventional Commits](https://www.conventionalcommits.org/).

## License

MIT, see [LICENSE.md](LICENSE.md). The CLI skeleton is derived from [DLoad](https://github.com/php-internal/dload)
(BSD-3-Clause), see [NOTICE.md](NOTICE.md).
