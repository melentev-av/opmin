# How opmin is tested (Testo cheat sheet)

opmin is tested **only with [Testo](https://php-testo.github.io/)**. PHPUnit is not a dependency of the tool:
the tests of the *analyzed projects* run with their own runner through adapters.

Testo is 0.x and is not PHPUnit. Before writing a test, check this file; when it is not enough, read the skills
shipped with Testo in `vendor/testo/testo/skills/` (`testo-write-tests`, `testo-data-driven`, `testo-run-tests`,
`testo-coverage`, `testo-configure`) and the sources in `vendor/testo/`. Never guess the API by analogy with
PHPUnit. When this file is wrong or incomplete, fix it in the same change.

Pinned versions (exact, update in a separate commit with a full test run): `testo/testo` 0.10.55,
`testo/bridge-rector` 0.3.3, `rasuvaeff/property-testing-core` 1.2.0, `rasuvaeff/property-testing-testo` 1.1.0.
Testo is split into ~15 sub-packages with independent versions (`testo/assert`, `testo/data`, …), so "all Testo
packages of one version" is impossible literally: the direct dependencies are pinned exactly, the rest is held
by `composer.lock`.

## Suites (`testo.php`)

| Suite | Location | What |
|---|---|---|
| `unit` | `tests/Unit` | pure classes: config, dump parser, comparator, detectors |
| `integration` | `tests/Integration` | `bin/opmin` as a process, commands on fixtures, verifier on before/after pairs |
| `rector` | `src/Rector`, `tests/Rector` | Rector rules with `#[TestRectorFixtures]`, each `*.php.inc` is a case |

```bash
composer test                 # all suites
composer test:unit            # one suite (also test:integration, test:rector)
vendor/bin/testo --json --filter='ConfigLoaderTest::rejectsInvalidDocument'   # one test (machine-readable)
vendor/bin/testo --json --path=tests/Unit/Module/Config                         # one directory
vendor/bin/testo --coverage --coverage-clover=runtime/coverage/clover.xml        # coverage (pcov or xdebug)
```

Exit code is 0 only for a passed run. A run with **zero tests is not green** (status `risky`, non-zero exit) —
usually a typo in `--filter`/`--suite`. For an agent or a script, parse `--json`, gate on `"status": "passed"`.

## Unit test

```php
#[Test]                                  // class-level: every public void method is a test
#[Covers(ConfigLoader::class)]
final class ConfigLoaderTest
{
    public function emptyDocumentGivesNoValues(): void
    {
        $values = ConfigLoader::loadString('');

        Assert::same($values, []);       // ORDER: actual, expected (opposite to PHPUnit!)
    }
}
```

- No base class, `final`, Arrange / Act / Assert separated by blank lines, no `// Arrange` comments.
- `Assert::same/equals/true/false/null/count/contains/instanceOf`, typed chains:
  `Assert::string($s)->contains('x')`, `Assert::array($a)->hasKeys('a', 'b')->sameElementsAs([...])`,
  `Assert::string($err)->ignoringWhitespace(lineBreaks: true)->contains(...)` (console output wraps lines).
- A test without assertions is `risky`. If it only checks "does not throw", mark it `#[ExpectNoAssertions]`.
- Setup/teardown: `#[BeforeTest]`, `#[AfterTest]`, `#[BeforeClass]`, `#[AfterClass]` on methods (no `setUp`).

## Expected exception

```php
public function rejectsUnknownKey(): never        // return type never
{
    Expect::exception(ConfigException::class)     // BEFORE the call, never try/catch
        ->withMessageContaining('Unknown config key');

    ConfigLoader::loadString("foo: 1\n");
}
```

`->withMessage('exact')`, `->withMessageContaining()`, `->withMessageMatchingRegex()`, `->withCode()`.

## Data provider (trap tables)

```php
public static function invalidDocuments(): iterable           // public static, iterable
{
    yield 'unknown key' => ["foo: 1\n", 'Unknown config key `foo`'];   // label => arguments
}

#[DataProvider('invalidDocuments')]
public function rejectsInvalidDocument(string $yaml, string $message): never { ... }
```

Every row is a separate case in the report, its id ends with `:<provider>:<row>` (`…::rejectsInvalidDocument:0:5`)
and can be passed back to `--filter`. Small inline sets: repeated `#[DataSet([args], 'label')]`.
Verifier trap fixtures follow this shape: `[name, code before, code after, expected verdict]`.

## Integration test: run `bin/opmin` as a process

See `tests/Integration/Command/CliTest.php`: a temp project directory per test (`#[BeforeTest]`/`#[AfterTest]`),
`proc_open([PHP_BINARY, 'bin/opmin', '--no-ansi', ...], cwd: $dir)`, assertions on exit code, stdout, stderr.
Prefer this over `CommandTester` for anything that touches config discovery (it depends on the current directory).

## Rector rule fixtures (`testo/bridge-rector`)

```php
#[TestRectorFixtures('Fixture/ExtractRepeatedPropertyFetch')]   // relative to the rule file
final class ExtractRepeatedPropertyFetchRector extends AbstractExtractRepeatedReadRector { ... }
```

- opmin's rules live in `src/Rector/Rule/`, their fixtures in `src/Rector/Rule/Fixture/<Rule>/*.php.inc`.
- Fixture `*.php.inc`: input, a line `-----`, expected output. **No separator = the rule must not change the
  code** — write every negative case and every trap this way, and name traps `trap_*.php.inc`.
- The `rector` suite with `RectorTestingPlugin` finds rules with the attribute in its locations and runs each
  fixture as a data set (`SmokeRector::fixture:0:0`). Each rule gets a fresh Rector container.
- Coverage of fixtures is scoped to the rule automatically; add `#[Covers(Rule::class)]` + `#[Covers(Helper::class)]`
  on the rule to widen it.
- **Configurable rules: not supported.** `RectorRunner` registers the rule with `$rectorConfig->rule($rule)`
  without configuration; its constructor accepts `$sets`, but `RectorFixtureInterceptor` never passes them
  (testo/bridge-rector 0.3.3). So a fixture always runs the rule with its defaults. Until this is fixed upstream
  (candidate issue/PR to php-testo/testo), test non-default options with a small subclass of the rule that calls
  `configure()` in its constructor, placed next to its fixtures in `tests/Rector` — see
  `tests/Rector/FullyQualifyGlobalCallsWithMocksRector.php` (the rule is not final for that, with a comment).
- The rules get the project's data (the shadow index, the internal functions of `php.binary`) through options;
  without options they fall back to an empty index and the PHP running the tests.
- **Positive fixtures must save opcodes**: `tests/Integration/Rector/RuleGainTest.php` counts the input and the
  expected output of every positive fixture under `php.binary` (the CI matrix: 8.1–8.5). It caught a fixture the
  optimizer folds into a constant anyway — a fixture that saves nothing proves nothing about the rule.
- `bin/bench` measures the opcode effect of the rules' rewrites on a `php.binary` (`--php=`); the defaults
  (`min_reads`) and the claims in the rule docblocks come from it.
- Smoke rule: `tests/Rector/SmokeRector.php`.

## The optimize pipeline

- `tests/Unit/Module/Optimize/`: the line diff (a property test rebuilds both texts from the patch), units and
  splicing, readability metrics, the signature gate.
- `tests/Integration/Module/Optimize/`: the formatter (a fake formatter script run through `php.binary`), the rule
  catalog (sets are expanded in a PHP process of their own: Rector keeps registered rules in static state).
- `tests/Integration/Command/OptimizeTest.php`: `opmin optimize` as a process on a small git project — a commit per
  step, rolled-back functions with their reasons, copy mode, dry run, a dirty tree. With `TMPDIR` inside this
  repository (the Docker wrapper) the test project sits in an ignored directory of opmin's own repository: the
  workspace treats a project whose files git does not track as not under git.

## Property tests (`rasuvaeff/property-testing-testo`)

```php
#[Property(runs: 300)]
public function intRoundTripsThroughString(int $value): void { ... }

/** @return array<string, \Rasuvaeff\PropertyTesting\ArbitraryInterface> */
public static function intRoundTripsThroughStringGenerators(): array   // <method>Generators, public static
{
    return ['value' => Gen::int()];
}
```

- Lists: `Gen::arrayOf(Gen::elements([...]), $min, $max)` (there is no `listOf`).
- No plugin registration: `#[Property]` registers itself. Do not combine with `#[DataProvider]` or
  `#[ExpectException]` (use `#[Property(throws: X::class)]`).
- A failure prints the shrunk counterexample and a seed: reproduce with `#[Property(seed: N)]` or `PROPERTY_SEED=N`.
- The verifier uses `property-testing-core` directly, behind opmin's own interfaces — see the next section.

## The verifier on `property-testing-core` (programmatic use)

`rasuvaeff/property-testing-core` 1.2.0 is a **runtime** dependency (the differential tester runs it); the old
`rasuvaeff/property-testing` is abandoned and must not be used. What was learned from its README, `llms.txt` and
sources in `vendor/rasuvaeff/property-testing-core/`:

- **Runner without a test framework:** `new PropertyRunner()->run(PropertyDefinition, TrialExecutor, listeners,
  corpus)`. It never prints, exits or reads the environment, and returns a result object: `Passed`, `Falsified`
  (`counterExample()`: `originalArguments`, `shrunkArguments`, `shrinkSteps`, `isFlaky()`, `failure`),
  `RegressionFailed` (a corpus entry still fails), `GaveUp` (discards), `TimeBudgetExceeded` (`budgetMs`) and a few
  more opmin does not use.
- **Executor:** `TrialExecutor::execute(array $arguments): TrialOutcome` — `passed()`, `failed($e)`, `discarded()`.
- **Own generators:** `ArbitraryInterface::generate(Random): Shrinkable`; `Shrinkable::of($value, fn() => iterable)`
  is the lazy shrink tree. There is no `shrink(mixed)`: candidates are attached at generation time.
- **Shrinking:** greedy descent through the tree; a candidate is accepted only if it fails *the same way* (same
  exception class thrown from the same line of the body's file). `flakyReplays` (2) re-runs the minimal
  counterexample; one that passes marks it flaky.
- **Seed:** explicit `PropertyConfig::$seed` gives the same inputs (`Random` is an object-scoped MT19937).
- **Corpus:** `FilesystemCorpus($dir)` stores a minimised input verbatim as JSON (array values only — objects
  become "seed" entries that replay the whole run) and replays it as one run before the random phase.
- Sequential only: do not run two properties concurrently in one process.

How opmin uses it (`src/Module/Verification/Property/`):

- The verifier sees only `PropertyRunner`, `InputGenerator`, `InputShrinker`, `RandomSource` and `Discard`;
  `Core/CorePropertyRunner` is the only class that touches the engine, so it can be replaced.
- The property has **one parameter — the whole `Input`** (argument recipes, `$this`, closure `use` values) as an
  array, so corpus entries are plain JSON and replay as one run.
- Generation and shrink candidates are opmin's: inputs are *recipes* for the harness, not PHP values, and the
  shrinker works on any input — generated, mutated by the coverage search or replayed from the corpus — which a
  shrink tree of the engine's own generators could not do. The engine contributes the run loop, seeds, the
  descent, flaky replays and the corpus.
- Examples (boundary values, literals of the body) are returned by the first draws of the arbitrary, so a failing
  example is shrunk like any other input (the engine's own `examples` are never shrunk).
- `budgetMs` ends a run with `TimeBudgetExceeded`; for opmin that is "held for what was checked", not a failure.

## Opcode counting tests

Opcode counts depend on the PHP that compiles the code (`php.binary`), not on the PHP running opmin.

- **Captured dumps** — `tests/Fixtures/Dumps/<minor>/<Fixture>.txt`: the OPcache dump (both phases) of
  `tests/Fixtures/Count/*.php` taken on every minor version of the matrix by `tests/Fixtures/Dumps/capture.sh`
  (Docker, official `php:<ver>-cli` images; the patch version is in `VERSION`). Unit tests of the parser and of
  the matcher run on all of them, so a format change of any version shows up without that PHP installed.
- **Hand-checked counts** — `tests/Fixtures/Count/expected.php`: `ops_opt` per function and per minor version,
  read from the dumps with awk, not with opmin. The unit tests (captured dumps) and the integration test
  (`opmin count` under the current `php.binary`) both compare with it.
- **Fixtures are not formatted**: `tests/Fixtures` is excluded from the code style, because a reformatted fixture
  changes lines and opcodes and no longer matches its dumps. After changing a fixture, run `capture.sh`, update
  `expected.php` by reading the new dumps, and check the result with awk.
- **`OPMIN_TEST_PHP_BINARY`** selects `php.binary` for the integration tests (default: the PHP running the tests,
  see `tests/Integration/TestPhp.php`). CI runs the integration suite with 8.1–8.5 (job `🔢 Opcodes`). Locally,
  a Docker wrapper works if it mounts the repository at the same path and the temp directory is inside it.
  `bin/playground wrapper 8.1` writes one for the `opmin-dev:8.1` image (`playground-seeds/docker/Dockerfile`:
  the official `php:8.1-cli` plus pcov, pcntl and composer — what a developer machine may lack):
  ```bash
  bin/playground wrapper 8.1
  TMPDIR=$PWD/playground/.tmp OPMIN_TEST_PHP_BINARY=$PWD/playground/.bin/php8.1 composer test:integration
  ```
  The wrapper runs `docker run --init`: opmin stops a hung harness worker with SIGTERM, which `docker run` forwards
  to the container; without an init process PHP as PID 1 ignores it and the container outlives the client.
  `bin/playground run --all --php=8.4` runs the playground with such a php.binary: with pcov the project's tests
  get coverage maps, so selective test runs and the `tests` status of `opmin verify` are exercised too.
- **Harness tests** — `tests/Integration/Module/Harness/WorkerTest.php` drives `harness/worker.php` under the same
  php.binary through the orchestrator's client: protocol, recipes, descriptions, `exit()`, fatal errors, timeouts.
- **Property test of the whole chain** — `tests/Integration/Module/Opcode/CountPropertyTest.php` generates valid
  PHP (nested closures, anonymous classes, several closures on a line, enums, traits) and checks that every
  function gets a count. Raise `runs` locally after touching the parser, the locator or the matcher.

## Verifier tests

- **Harness** — `tests/Integration/Module/Harness/` (worker protocol, sessions) under the php.binary of the run.
- **Comparator** — `tests/Unit/Module/Verification/Compare/`: a table of differences and equivalences plus
  property tests (reflexivity, symmetry, `-0.0`, `NAN`).
- **Inputs** — `tests/Unit/Module/Verification/Input/`: phpdoc types, the plan of boundary values and literals,
  reproducible random inputs, termination of shrinking (property test), `Coverage/InstrumenterTest` for probes.
- **Differential tester** — `tests/Integration/Module/Verification/DiffTesterTest.php`: mechanics (shrinking to
  the magic literal, `exit()`/hang of the changed version as a result, nondeterminism, static chains, closures,
  mocks); `VerifierTrapsTest` is the table of traps and equivalent pairs of the brief.
- With php.binary in Docker every worker start is a container start: a run takes seconds, not milliseconds.

## Code style of the tests

Run `composer cs:fix` (or `cs:diff`), not `php-cs-fixer fix <path>`: with explicit paths PHP-CS-Fixer ignores the
config's exclusions and reformats `tests/Fixtures`, whose lines and opcodes are compared with captured dumps.

## A test that cannot fail is worse than no test

For every new suite, rule or fixture table: break the code or the expectation once and see the test go red.
This was done for all three suites in M0 (broken fixture, broken `ValueCaster`, broken exit code of config errors).

## Mutation testing (Infection)

The verifier is the critical part: its tests are measured with Infection through `testo/bridge-infection`
(`infection.json`, sources `src/Module/Verification`, `Analysis`, `Harness`; target MSI ≥ 80%). Infection needs a
coverage driver, so locally it runs in the dev image; CI runs it weekly (`🧬 Mutation testing`).

```bash
bin/playground php 8.4 bin/infect          # coverage once, then every segment; fails below MIN_MSI=80
bin/playground php 8.4 bin/infect Input    # one segment, reusing the last coverage
```

Survivors are in `runtime/infection/mut/<segment>.gitlab.json` (the diff of each mutant). Excluded on purpose
(`mutators.global-ignore`): the random distributions of `ValueGenerator` (`randomInt`, `randomFloat`,
`randomString`, `randomKey`, `randomMixed`) — tuning whose effect the trap table measures, not exact values.
Survivors that are equivalent mutants (a cast the types already guarantee, `true`/`false` in a set read with
`isset`, a timeout constant) are left as they are rather than bent into tests.

## Coverage

Testo collects coverage with pcov or Xdebug (`coverage` mode) through `--coverage`/`--coverage-clover=`; the CI
job `📊 Coverage` runs it with pcov and uploads `clover.xml`. `--coverage` alone fails when no driver is loaded —
a cheap check that the driver is really there.
