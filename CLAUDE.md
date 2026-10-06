# opmin — notes for coding agents

opmin minimizes PHP opcodes per function without changing behavior. The full specification is
[docs/brief.md](docs/brief.md); work strictly stage by stage (M0 → M8), the verifier (behavior checks) wins over
speed every time.

- Always follow the guidelines in `docs/guidelines/`:
  - [how-to-write-console-command.md](docs/guidelines/how-to-write-console-command.md) — commands, exit codes, config access;
  - [how-to-write-php-code-best-practices.md](docs/guidelines/how-to-write-php-code-best-practices.md) — code style, config keys;
  - [how-to-write-tests.md](docs/guidelines/how-to-write-tests.md) — tests on Testo.
- Tests are on **Testo**, not PHPUnit: read [docs/testing.md](docs/testing.md) before writing one.
- Checks before a commit: `composer test`, `composer psalm`, `composer cs:diff`, `composer schema:dump` (no diff).
- Commits: Conventional Commits (`feat(config): ...`, `fix(harness): ...`), no AI attribution lines.
- Branches: work in `develop`; `master` receives only release merges.
- Manual end-to-end runs: `bin/playground init`, `bin/playground run --all` (see the brief, section «Playground»).
- Code that depends on the production PHP (opcode counting, the harness, project tests, PHPStan) always runs in a
  subprocess through `php.binary`, never in the PHP running opmin.
