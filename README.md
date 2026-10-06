# opmin

**Minimizes PHP opcodes without changing behavior.**

opmin counts opcodes of every function, method and closure (after the OPcache optimizer), rewrites code with
Rector rules and an LLM stage, and keeps a change only when the opcode count went down **and** the behavior is
proven unchanged: syntax and PHPStan, the project's own tests, and differential testing of the old and the new
version of each function on generated inputs (return values, exceptions, output, warnings, by-ref arguments,
object state, calls to collaborators).

As a CI guard, `opmin check` fails a pull request when functions grow in opcodes compared to a committed baseline.

> **Status: early development.** The CLI skeleton, configuration and build (M0) and opcode counting —
> `count` and `diff` (M1) — work; `optimize`, `check` and the rest are being implemented stage by stage.
> Commands that are not implemented yet fail with a message naming the stage.

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
