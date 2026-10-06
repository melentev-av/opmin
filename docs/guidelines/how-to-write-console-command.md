# How to write a console command

- A command extends `Opmin\Command\Base` and has `#[AsCommand(name: ..., description: ...)]`.
- Register it in `bin/opmin` (`FactoryCommandLoader`, lazy creation) — an unregistered command does not exist.
- `configure()`: call `parent::configure()` first (it adds `--config` and `--set`), then arguments and options.
- `execute()`: call `parent::execute($input, $output)` first. It loads and validates `opmin.yaml`, hydrates every
  config section and fills the container (`InputInterface`, `OutputInterface`, `StyleInterface`, `Logger`).
  Configuration errors are reported by `Base::run()` with exit code 2 — never catch `ConfigException` yourself.
- Get settings from the container as config DTOs: `$this->container->get(Schema\Verification::class)`. Do not read
  `$input->getOption()` for something that is a config key — the inflector already merged CLI, env and file.
- Return codes: `Command::SUCCESS` (0), `Command::FAILURE` (1), `Command::INVALID` (2, invalid config/usage).
  Commands with their own meaning of codes (`check`: 1 — opcodes grew, 2 — baseline from another PHP) document
  them in the class docblock.
- A command that is not implemented yet extends `NotImplemented` and sets `STAGE`: it fails with exit code 1, so a
  placeholder never looks like a successful run in CI.
- Paths are `Internal\Path` value objects, not strings.
- Interactive questions only when `$input->isInteractive()`; every question has a flag for non-interactive mode.
- Anything that compiles or runs the analyzed code goes through `php.binary` in a subprocess — never through the
  PHP that runs opmin (it is the embedded PHP of the static binary).
