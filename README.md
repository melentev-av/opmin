# opmin

**Minimizes PHP opcodes without changing behavior.**

opmin counts opcodes of every function, method and closure (after the OPcache optimizer), rewrites code with
Rector rules and an LLM stage, and keeps a change only when the opcode count went down **and** the behavior is
proven unchanged: syntax and PHPStan, the project's own tests, and differential testing of the old and the new
version of each function on generated inputs (return values, exceptions, output, warnings, by-ref arguments,
object state, calls to collaborators).

As a CI guard, `opmin check` fails a pull request when functions grow in opcodes compared to a committed baseline.

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
curl -fsSL https://raw.githubusercontent.com/melentev-av/opmin/master/install.sh | sh -s -- --dir=/usr/local/bin --version=0.2.0
opmin self-update            # the latest release; --check only tells; --to=<version> installs that one
```

`install.sh` puts `opmin` into `~/.local/bin` (or `--dir`), checks the SHA-256 against `sha256sum.txt` of the
release and, with `openssl`, the signature of `sha256sum.txt`. `self-update` always checks both and starts the new
binary once before replacing the old one. Archives are also on
[GitHub Releases](https://github.com/melentev-av/opmin/releases) — see «Verifying a download» below.

**2. PHAR** — the same scoped build for your own PHP 8.3+: `opmin.phar` on the release page, or as a composer
package without dependencies (`composer require --dev melentev-av/opmin`, the PHAR inside, never conflicting
with your `vendor/`).

**3. Docker** — `ghcr.io/melentev-av/opmin:<version>-php<8.1…8.5>`: the binary plus the PHP of production with
OPcache (JIT off), pcov, git and composer. Pick the tag of your production PHP; the images are meant for CI and
for git packages.

```bash
docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:0.2.0-php8.3 opmin doctor
```

**Pinning the version per project.** Commit a `.opmin-version` (`0.2.3`, or a constraint like `^0.2`) or set
`requires: '^0.2'` in `opmin.yaml`: another opmin stops with exit code 2 and the `opmin self-update --to=` command
to run. A global `opmin` started inside a project that has its own — `vendor/bin/opmin` (the composer package) or
an `opmin` binary in the project root (e.g. downloaded by [dload](https://github.com/php-internal/dload)) — runs
that one instead (`OPMIN_NO_DELEGATE=1` keeps the global one).

**From sources** (development): `git clone git@github.com:melentev-av/opmin.git && cd opmin && composer install &&
bin/opmin list`.

### Verifying a download

Every release has `sha256sum.txt`, its ECDSA P-256 signature `sha256sum.txt.sig` and GitHub build provenance
attestations. The public keys are [resources/release-keys](resources/release-keys) (a primary one and a reserve
one kept offline):

```bash
sha256sum --check --ignore-missing sha256sum.txt
openssl dgst -sha256 -verify primary.pub.pem -signature sha256sum.txt.sig sha256sum.txt
gh attestation verify opmin-0.2.0-linux-x86_64.tar.gz --repo melentev-av/opmin
```

## Quick start

```bash
opmin init        # opmin.yaml: every key with a comment; detects php.target, paths, test runner, formatter, PHPStan
opmin doctor      # php.binary first, then OPcache, the harness, syntax, tests, PHPStan, coverage, git — with fixes
opmin count       # opcodes per function
opmin optimize    # Rector stage, every accepted step is a separate commit
# then, in Claude Code: "minimize opcodes with opmin" — the skill opcode-minimize (LLM stage)
opmin baseline    # commit opmin.baseline.json, then `opmin check` in CI keeps opcodes from growing
```

A run ends with a summary like this, and `runs/<ts>/report.md` with every function:

```text
 ------ ------------------------------------ ------ ------------- --------- ----------------
  Pass   Rule                                 Kept   Rolled back   Opcodes   Commit / error
 ------ ------------------------------------ ------ ------------- --------- ----------------
  1      FullyQualifyGlobalCallsRector        14     2             -85       039d2f6a
  1      ExtractRepeatedPropertyFetchRector   2      1             -6        bc951c85
  1      ExtractRepeatedArrayDimFetchRector   1      1             -2        4d3f2f4b
 ------ ------------------------------------ ------ ------------- --------- ----------------

The full run of the project's tests passes on the result.

 [OK] 442 → 349 opcodes (-93). Report: runs/20261007-192240/report.md, patch: runs/20261007-192240/opmin.patch
```

`opmin doctor` exits with 1 when something blocks `count`/`optimize` (warnings — e.g. no pcov, so every step
runs the whole test suite — do not); `--with-tests` also runs your test suite once.

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
  re-counting an unchanged project does not start PHP for compilation at all. A relative `cache.dir` is next to
  the config in use (the current directory without one), so in a monorepo the packages share the root's cache.
  `cache.driver: memory` keeps counts and references for one run only, without a file per entry on disk.

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
opmin optimize --review                # confirm every change: y / n / a (all of this rule) / q
opmin optimize --resume                # continue the latest interrupted run (or --resume=runs/<ts>)
opmin optimize --guard-perf            # also roll back changes that make a function slower
opmin optimize --rector-rule='Opmin\Rector\Rule\FullyQualifyGlobalCallsRector'
```

- Rules are applied one by one, in passes until a pass changes nothing (`rector.max_passes`). After each rule:
  the project's formatter on the changed files (Pint, PHP-CS-Fixer, ECS, PHPCBF — detected, or `commands.format`,
  `--format=none`), `php -l`, a count. A changed function is kept only when it saves opcodes, the gain is worth
  the changed lines (`readability.*`), complexity and nesting do not grow and its signature stays as
  `signatures.*` allow; then it is verified on all three levels. Everything else is taken back.
- In a git working tree (must be clean) every accepted step is a commit; outside git the originals are copied to
  `runs/<ts>/original/`; `--dry-run` restores everything. Each run writes the report (below), the counts before
  and after and `opmin.patch`, and ends with a full run of the project's tests.
- `--review` shows each change that passed every check — the diff of the function, `−N opcodes, rule X, checks:
  diff-tested 96% (210 inputs) ✓` — and asks `y` (apply), `n` (roll back and never propose again), `a` (apply this
  and the rest of the rule), `q` (roll back and end the run). Declined changes go to `opmin.baseline.yaml`
  (`rejected: [{function, rule}]`, committed at the end of the run; remove an entry to allow it again) and are
  skipped by both stages. Without an interactive session (`-n`, CI, an agent) every change that passes is applied.
- `--guard-perf` (or `guard_perf.enabled`) times every diff-tested change: both versions on up to 20 of the inputs
  of its differential test, compiled by OPcache as in production, in alternating order over 7 rounds; the median
  change of the time goes to the report, and a change slower by more than `guard_perf.max_regression_percent`
  (5) is rolled back even with fewer opcodes. Changes proven only by the project's tests are not timed.
- Ctrl+C (SIGINT, SIGTERM) stops a run: the step under way is dropped — its tools got the signal too — and the
  report says the run was interrupted. Every step is saved to `runs/<ts>/state.json`; `--resume` puts the files
  back to the last accepted step (also after a crash or `kill -9`, dropping an opmin commit the state missed) and
  continues with the same files, rules and options, ending as an uninterrupted run would.
- A change rolled back for a reason of its own (no gain, a difference, not proven…) is not tried again in later
  passes while the function stays the same.
- opmin's own rules, on by default, prove their safety conditions themselves (native types through PHPStan):
  `FullyQualifyGlobalCallsRector` (`strlen()` → `\strlen()`, only when no namespaced function or test mock —
  php-mock, ClockMock — can shadow it), `ExtractRepeatedPropertyFetchRector` (readonly properties across calls,
  mutable ones while no user code runs, no `__get`/hooks), `ExtractRepeatedArrayDimFetchRector` (only keys proven to
  exist), `HoistLoopInvariantCountRector` (`\count()` of an unchanged local array out of a `for` condition). For the
  two extraction rules, a call of a known pure built-in function (`\explode()`, `\strlen()`, `\abs()`…) with
  arguments of scalar native types runs no user code (`Opmin\Rector\Support\PureFunctionCall`).
- Code can be excluded from every rule or from some: see [Excluding code](#excluding-code).
- Which standard Rector rules save opcodes: [docs/standard-rules.md](docs/standard-rules.md).

## Optimizing (Stage B: LLM, a Claude Code skill)

What rules cannot do, a model proposes and opmin judges — one function at a time, through the same checks as a
Rector step. `opmin init` puts the skill `opcode-minimize` into `.claude/skills/` (`--no-skill` to skip);
`opmin skill:install --global` puts it into `~/.claude/skills/`, `opmin skill:update` brings it to the version of
the installed opmin. The skill drives these commands:

```bash
opmin llm:targets src --format=json            # starts a session in runs/<ts>/: the llm.top_n functions with the most opcodes
opmin llm:context 'App\Cart::total'            # source, opcode listing, histogram, dynamic constructs with their rules,
                                               # readability and signature rules, rejected attempts with their reasons
opmin apply-candidate 'App\Cart::total' runs/<ts>/candidate.php --format=json
opmin llm:finish                               # the full test run (taking back attempts while it fails), report, patch
```

- A candidate is the new source of **only that function**; anything else changed (another function, an import) is
  refused. It is kept only with strictly fewer opcodes, within `readability.*` and `signatures.*`, and proven by
  PHPStan, the project's tests and differential tests; then it is a commit. A rejection names the reason — with
  the shrunk counterexample input when behavior differs — and goes into the context of the next attempt.
- At most `llm.attempts_per_function` attempts per function. `#[\Opmin\Ignore(rules: ['llm'])]` /
  `@opmin-ignore llm` keeps a function away from this stage only.
- The patterns the skill knows are measured on PHP 8.1–8.5 (`bin/bench --only=patterns`), including the ones that
  usually give nothing: [resources/skills/opcode-minimize/PATTERNS.md](resources/skills/opcode-minimize/PATTERNS.md).

## The report

Every run (`optimize`, `llm:finish`) writes `runs/<ts>/report.md` for people and `runs/<ts>/report.json` for tools:

- the total: opcodes before → after, %; whether the full run of the project's tests passes;
- every changed function: before → after, what saved the opcodes (rule or LLM), how it is proven (`diff-tested`,
  `tests`, `unverified`), the branch coverage of the differential tests, the project's tests that run it, the flags
  of dynamic constructs; changes that save executed opcodes rather than static ones are listed apart;
- every rolled-back change grouped by reason (a difference with its counterexample input, failing tests, PHPStan,
  not proven, signature, dynamic constructs, readability, no gain, excluded);
- counterexamples as tests of the project's runner in `runs/<ts>/counterexamples/`;
- the environment: PHP runtime, `php.target`, the optimizer settings, opmin, Rector, the project's PHPStan. A run
  warns when one of them changed since the previous run, `opmin diff` when the reports come from other opmin
  versions or targets.

`report.json` has `"schema": 1`; its fields change only in a major release (new fields may be added).

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
  warning. `--suggest` applies every Stage A rule alone to temporary copies (the project is not touched) and
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

The baseline must be taken with the same PHP minor version as the check: in CI use the image with the PHP of
your production, and create the baseline with the same image
(`docker run --rm -v "$PWD:/app" ghcr.io/melentev-av/opmin:<version>-php8.3 opmin baseline`), `<version>` being
the opmin release.

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
- Rule names in `rules:` and `@opmin-ignore` — an alias or the short class name of any rule, case-insensitive:

  | Alias | Rule |
  |---|---|
  | `fqn` | `FullyQualifyGlobalCallsRector` |
  | `property_fetch` | `ExtractRepeatedPropertyFetchRector` |
  | `array_dim_fetch` | `ExtractRepeatedArrayDimFetchRector` |
  | `count` | `HoistLoopInvariantCountRector` |
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
- `runs/<ts>/` with the report and `opmin-<package>-<ref>.patch` land in the current directory: apply the patch in
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
  'cd /tmp && opmin optimize https://github.com/briannesbitt/Carbon.git --ref=3.14.2 --no-docker --yes -n; cp -r runs *.patch /out/'
```

## Results on real packages

An example: real packages optimized in git package mode with Stage A (the four own Rector rules), then their own
tests run on the patched code and stay green. Measured on 2026-10-08 with opmin 0.2 in CI; the run can be repeated by
hand ([smoke-real-packages.yml](.github/workflows/smoke-real-packages.yml), or `tests/Smoke/run.sh <package> <php>`
locally).

| Package | PHP | Opcodes | Saved | Functions changed | Package's tests after the patch | Time |
|---|---|---|---|---|---|---|
| symfony/string 6.4.46 | 8.1 | 5 940 → 5 863 | −77 (−1.3%) | 50 | green | 2.5 min |
| | 8.5 | 6 358 → 6 083 | −275 (−4.3%) | 45 | green | 2.5 min |
| league/csv 9.28.0 | 8.1 | 16 679 → 16 605 | −74 (−0.4%) | 24 | green (without `network`) | 5 min |
| | 8.5 | 16 512 → 16 404 | −108 (−0.7%) | 26 | green (without `network`) | 1 min |
| nesbot/carbon 3.14.2 | 8.1 | 26 823 → 26 630 | −193 (−0.7%) | 52 | green (without `localization`) | 7 min |
| | 8.5 | 27 413 → 26 161 | −1 252 (−4.6%) | 105 | green (without `localization`) | 21 min |

`php.binary` and `php.target` are the PHP of the row; CI runners, the static binary. Most of the gain is
`FullyQualifyGlobalCallsRector`, and the gap between 8.1 and 8.5 is its: since PHP 8.4 an unqualified call of a
frameless function (`trim`, `str_replace`, `implode`…) in a namespace compiles into both the frameless call and the
fallback, and `\trim()` removes the fallback.

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
