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
final class ExtractRepeatedPropertyFetchRector extends AbstractRector { ... }
```

- Fixture `*.php.inc`: input, a line `-----`, expected output. **No separator = the rule must not change the
  code** — write every negative case and every trap this way.
- The `rector` suite with `RectorTestingPlugin` finds rules with the attribute in its locations and runs each
  fixture as a data set (`SmokeRector::fixture:0:0`). Each rule gets a fresh Rector container.
- Coverage of fixtures is scoped to the rule automatically; add `#[Covers(Rule::class)]` + `#[Covers(Helper::class)]`
  on the rule to widen it.
- **Configurable rules: not supported.** `RectorRunner` registers the rule with `$rectorConfig->rule($rule)`
  without configuration; its constructor accepts `$sets`, but `RectorFixtureInterceptor` never passes them
  (testo/bridge-rector 0.3.3). So a fixture always runs the rule with its defaults. Until this is fixed upstream
  (candidate issue/PR to php-testo/testo), test non-default parameters (`min_reads`) with a small subclass of the
  rule that fixes the parameter in its constructor, placed next to the fixtures in `tests/Rector`.
- Smoke rule: `tests/Rector/SmokeRector.php` keeps the suite meaningful until opmin's own rules exist.

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

- No plugin registration: `#[Property]` registers itself. Do not combine with `#[DataProvider]` or
  `#[ExpectException]` (use `#[Property(throws: X::class)]`).
- A failure prints the shrunk counterexample and a seed: reproduce with `#[Property(seed: N)]` or `PROPERTY_SEED=N`.
- The verifier (M2) uses `property-testing-core` directly, behind opmin's own `InputGenerator`/`PropertyRunner`
  interfaces; how to drive the core runner programmatically is to be documented here in M2.

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
  a Docker wrapper works if it mounts the repository at the same path and the temp directory is inside it:
  ```bash
  printf '#!/bin/sh\nexec docker run --rm -i -v "%s:%s" -w "$PWD" php:8.1-cli php "$@"\n' "$PWD" "$PWD" > playground/.bin/php8.1
  chmod +x playground/.bin/php8.1
  TMPDIR=$PWD/playground/.tmp OPMIN_TEST_PHP_BINARY=$PWD/playground/.bin/php8.1 composer test:integration
  ```
- **Property test of the whole chain** — `tests/Integration/Module/Opcode/CountPropertyTest.php` generates valid
  PHP (nested closures, anonymous classes, several closures on a line, enums, traits) and checks that every
  function gets a count. Raise `runs` locally after touching the parser, the locator or the matcher.

## A test that cannot fail is worse than no test

For every new suite, rule or fixture table: break the code or the expectation once and see the test go red.
This was done for all three suites in M0 (broken fixture, broken `ValueCaster`, broken exit code of config errors).

## Coverage

Testo collects coverage with pcov or Xdebug (`coverage` mode) through `--coverage`/`--coverage-clover=`; the CI
job `📊 Coverage` runs it with pcov and uploads `clover.xml`. `--coverage` alone fails when no driver is loaded —
a cheap check that the driver is really there.
