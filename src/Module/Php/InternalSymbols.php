<?php

declare(strict_types=1);

namespace Opmin\Module\Php;

use Symfony\Component\Process\Process;

/**
 * Internal functions and constants of `php.binary` — the PHP the code runs on in production, whose
 * extensions may differ from the PHP running opmin. A global function exists for the qualified call
 * only when it is here or declared by the project.
 *
 * @internal
 */
final readonly class InternalSymbols
{
    private const SCRIPT = <<<'PHP'
        $constants = [];
        foreach (get_defined_constants(true) as $extension => $group) {
            if ($extension !== 'user') {
                array_push($constants, ...array_keys($group));
            }
        }
        echo json_encode(['functions' => get_defined_functions()['internal'], 'constants' => $constants]);
        PHP;

    /**
     * @param list<string> $functions
     * @param list<string> $constants
     */
    public function __construct(
        public array $functions,
        public array $constants,
    ) {}

    /**
     * @throws PhpBinaryException
     */
    public static function of(PhpBinary $php): self
    {
        $process = new Process($php->command(['-r', self::SCRIPT]), timeout: 60);
        $process->run();
        /** @var mixed $data */
        $data = \json_decode($process->getOutput(), true);
        /** @var mixed $functions */
        $functions = \is_array($data) ? ($data['functions'] ?? null) : null;
        /** @var mixed $constants */
        $constants = \is_array($data) ? ($data['constants'] ?? null) : null;
        if (!$process->isSuccessful() || !\is_array($functions) || !\is_array($constants)) {
            throw new PhpBinaryException("Cannot list the internal functions of `{$php->path}`: " . \trim($process->getErrorOutput()));
        }

        /** @var list<string> $functions */
        $functions = \array_values(\array_filter($functions, 'is_string'));
        /** @var list<string> $constants */
        $constants = \array_values(\array_filter($constants, 'is_string'));

        return new self($functions, $constants);
    }

    /**
     * @return array{functions: list<string>, constants: list<string>}
     */
    public function toArray(): array
    {
        return ['functions' => $this->functions, 'constants' => $this->constants];
    }
}
