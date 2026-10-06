# PHP code standards

- PHP 8.3 for the tool (`src/`), PHP 8.1 without dependencies for the harness (`harness/`, M2) — see the brief.
- `declare(strict_types=1);`, PER-2 via `composer cs:fix` (spiral/code-style), Psalm level 1 (`composer psalm`).
- `final` classes by default, `readonly` properties and constructor promotion, `@internal` on everything that is not
  a public contract.
- Global functions and constants are fully qualified (`\strlen`, `\PHP_EOL`) — the style the tool itself recommends.
- Enums for fixed sets of values (backed enums when the value is written to config or JSON).
- Precise PHPDoc types: `non-empty-string`, `list<T>`, `array<K, V>`, `positive-int`, generics.
- Strict comparisons (`===`), `??` and `?->`; no `empty()`.
- Errors are exceptions with a message that tells the user what is wrong and where (key path, file, function);
  no silent fallbacks.
- Config: a new setting is a property with `#[ConfigKey('section.key', 'description')]` in a DTO of
  `src/Module/Config/Schema/`; new DTO classes are added to `ConfigSchema::SECTIONS`. After any change run
  `composer schema:dump` (CI fails on a stale `resources/opmin.schema.json`).
- Do not invent opcode "optimizations": every claim about opcodes is checked by a real count on the current PHP.
- Comments explain *why*, not *what*. Match the density of the surrounding code.
