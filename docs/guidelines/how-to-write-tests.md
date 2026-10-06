# How to write tests

All tests of opmin are written on **Testo**. The cheat sheet with working examples is [docs/testing.md](../testing.md) —
read it before writing a test and update it when you learn something new about Testo.

- Mirror the source tree: `src/Module/Config/ConfigLoader.php` → `tests/Unit/Module/Config/ConfigLoaderTest.php`.
- `#[Test]` and `#[Covers(...)]` on the class, `final class`, no base class.
- `Assert::same($actual, $expected)` — actual first.
- Expected exceptions: `Expect::exception(...)` before the call, method returns `never`.
- Tables of cases (traps, equivalent pairs, invalid inputs): `#[DataProvider]` with labeled rows.
- Integration tests run `bin/opmin` as a process in a temporary directory.
- Rector rules: fixtures `*.php.inc` next to the rule, negative cases without the `-----` separator.
- Property tests for invariants (comparator, parsers, casting) with `#[Property]`.
- Do not mock enums or final classes — use real instances; for interfaces write a small hand-made double.
- Every new test is seen red once (break the code or the expectation) before it is trusted.
- If Testo lacks something or has a bug — do not switch to PHPUnit and do not build workarounds; describe it
  with a minimal reproduction in the stage summary (a candidate issue for php-testo/testo).
