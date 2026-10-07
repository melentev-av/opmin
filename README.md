# opmin

**Minimizes PHP opcodes without changing behavior.**

opmin counts opcodes of every function, method and closure (after the OPcache optimizer), rewrites code with
Rector rules and an LLM stage, and keeps a change only when the opcode count went down **and** the behavior is
proven unchanged: syntax and PHPStan, the project's own tests, and differential testing of the old and the new
version of each function on generated inputs (return values, exceptions, output, warnings, by-ref arguments,
object state, calls to collaborators).

As a CI guard, `opmin check` fails a pull request when functions grow in opcodes compared to a committed baseline.

> **Status: early development.** The CLI skeleton, configuration and build (M0), opcode counting — `count` and
> `diff` (M1), behavior verification — `verify` (M2) and the Rector stage of `optimize` (M3) work; the LLM stage,
> `check` and the rest are being implemented stage by stage. Commands and options that are not implemented yet
> fail with a message naming the stage.

## Quick start (planned flow)

```bash
opmin init        # writes opmin.yaml with every key, its default and a comment
opmin doctor      # checks php.binary, OPcache, git, tests, PHPStan
opmin count src   # opcodes per function
opmin optimize .  # Rector + LLM stages, every accepted step is a separate commit
```

## Counting opcodes

```bash
opmin count src lib/Legacy.php                      # table: function, file:line, ops_raw, ops_opt, vars, tmps
opmin count --filter='App\Service\*' --exclude=tests
opmin count --format=json > before.json             # deterministic report, keys sorted
opmin diff before.json after.json                   # per function: fewer / more opcodes, new, removed
```

- Code is compiled by `php.binary` (your production PHP, 8.1–8.5) with OPcache, never executed and never by the
  PHP running opmin. `ops_opt` — opcodes after the optimizer — is the main metric; `ops_raw` is before it.
- Every function, method, property hook (8.4+), closure and the code outside functions (`src/x.php::<main>`,
  counted but not optimized) gets a stable key: `App\Foo::bar`, `App\Foo::bar::{closure:2}`,
  `App\Foo::bar::{class:1}::baz` for anonymous classes — ordinals, not line numbers. Trait methods are counted
  once, in the trait; abstract and interface methods are not reported.
- Counts depend on the PHP version: reports carry `php` and `optimizer_hash`, and `diff` refuses to compare
  reports taken with different ones.
- Counts are cached in `.opmin-cache/` by file content, PHP version, optimizer settings and opmin version:
  re-counting an unchanged project does not start PHP for compilation at all.

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
- A difference is shrunk to a minimal input and saved to `runs/<timestamp>/counterexamples/` as JSON and as a
  test for the project's runner (PHPUnit, Pest, Testo; a plain script otherwise).
- Accepted: proven by differential tests with `verification.min_branch_coverage` of the original's branches,
  or unverified but executed by passing project tests, or with `verification.allow_unverified`. Branches the
  project's PHPStan proves dead (`--with-tests`: always-true or impossible conditions, unreachable code) are
  not counted. Functions with I/O are never called with generated inputs: only the project's tests prove them.
- Flags of `opmin count` (`compact`, `static_var`, `eval`, `io`…) say which constructs restrict a function.

## Optimizing (Stage A: Rector)

```bash
opmin optimize                         # the config `paths` (src/ or app/)
opmin optimize src/Cart.php 'src/**/*Service.php' --dry-run
opmin optimize --with-standard-rector  # also the standard Rector rules (off by default)
opmin optimize --rector-rule='Opmin\Rector\Rule\FullyQualifyGlobalCallsRector'
```

- Rules are applied one by one, in passes until a pass changes nothing (`rector.max_passes`). After each rule:
  the project's formatter on the changed files (Pint, PHP-CS-Fixer, ECS, PHPCBF — detected, or `commands.format`,
  `--format=none`), `php -l`, a count. A changed function is kept only when it saves opcodes, the gain is worth
  the changed lines (`readability.*`), complexity and nesting do not grow and its signature stays as
  `signatures.*` allow; then it is verified on all three levels. Everything else is taken back.
- In a git working tree (must be clean) every accepted step is a commit; outside git the originals are copied to
  `runs/<ts>/original/`; `--dry-run` restores everything. Each run writes `runs/<ts>/report.json` (every kept and
  rolled-back function with the reason), the counts before and after and `opmin.patch`, and ends with a full run
  of the project's tests.
- opmin's own rules, on by default, prove their safety conditions themselves (native types through PHPStan):
  `FullyQualifyGlobalCallsRector` (`strlen()` → `\strlen()`, only when no namespaced function or test mock —
  php-mock, ClockMock — can shadow it), `ExtractRepeatedPropertyFetchRector` (readonly properties across calls,
  mutable ones while no user code runs, no `__get`/hooks), `ExtractRepeatedArrayDimFetchRector` (only keys proven to
  exist), `HoistLoopInvariantCountRector` (`\count()` of an unchanged local array out of a `for` condition).
- Exclude code with `#[\Opmin\Ignore]` / `#[\Opmin\Ignore(rules: ['fqn'])]`, `@opmin-ignore [rules]`,
  `ignore.paths`, `ignore.functions`; `vendor/` and `@generated` files are never touched.
- Which standard Rector rules save opcodes: [docs/standard-rules.md](docs/standard-rules.md).

## Installation

Planned: a static binary for Linux and macOS (no PHP needed to *run* opmin — but analyzing code always uses
your production PHP, `php.binary`), a scoped PHAR, and a Docker image with the PHP version of your production.
Until the first release, run from sources:

```bash
git clone git@github.com:melentev-av/opmin.git && cd opmin && composer install
bin/opmin list
```

## Configuration

`opmin.yaml` (or `opmin.yaml.dist`) in the project root; `opmin init` generates it with all keys. Unknown keys and
wrong types are errors naming the key. Any key can be overridden for one run:

```bash
OPMIN_VERIFICATION_SEED=7 opmin optimize .
opmin optimize . --set=verification.seed=7 --set=rector.standard.enabled=true
```

IDE completion comes from the JSON Schema referenced in the first line of the generated file
([resources/opmin.schema.json](resources/opmin.schema.json)).

## What opmin is not

- Not a profiler and not a guarantee of speed: fewer static opcodes is not always faster code
  (`--guard-perf` measures time where benchmarks exist).
- Not a code style tool: changes that do not reduce opcodes or cost too much readability are rejected.

## Development

```bash
composer test          # Testo: unit, integration, rector suites
composer psalm         # static analysis
composer cs:diff       # code style
make phar              # scoped PHAR in .build/phar/opmin.phar
bin/playground init    # manual end-to-end scenarios in playground/ (git-ignored)
```

Branches: development happens in `develop`; `master` only receives release merges. Commits follow
[Conventional Commits](https://www.conventionalcommits.org/).

## License

MIT, see [LICENSE.md](LICENSE.md). The CLI skeleton is derived from [DLoad](https://github.com/php-internal/dload)
(BSD-3-Clause), see [NOTICE.md](NOTICE.md).
