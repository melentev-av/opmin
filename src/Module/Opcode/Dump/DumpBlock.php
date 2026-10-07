<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Dump;

/**
 * One op_array of an OPcache dump.
 *
 * ```
 * App\Svc\top:
 *      ; (lines=5, args=1, vars=1, tmps=1)
 *      ; (after optimizer)
 *      ; /app/src/Svc.php:20-24
 * 0000 CV0($a) = RECV 1
 * ...
 * ```
 *
 * @internal
 */
final readonly class DumpBlock
{
    /**
     * @param string $name Header as printed: `$_main`, `ns\fn`, `Class::method`, `Class::$prop::get`,
     *        `ns\{closure}` (PHP < 8.4), `{closure:Parent():12}` (PHP 8.4+), `class@anonymous::m`.
     *        Names of anonymous classes are cut at their NUL byte.
     * @param int<0, max> $ops `lines=N`: the number of opcodes.
     * @param int<0, max> $lineStart First line (the `function`/`fn` keyword, not attributes).
     * @param array<non-empty-string, positive-int> $opcodes Opcode name => count, sorted by name.
     * @param string $listing The opcode lines as dumped (for the prompt of the LLM stage).
     */
    public function __construct(
        public string $name,
        public Phase $phase,
        public int $ops,
        public int $args,
        public int $vars,
        public int $tmps,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public array $opcodes,
        public string $listing = '',
    ) {}

    public function isMain(): bool
    {
        return $this->name === '$_main';
    }

    /**
     * `ns\{closure}` before PHP 8.4, `{closure:…}` since 8.4.
     */
    public function isClosure(): bool
    {
        return \str_starts_with($this->name, '{closure') || \str_ends_with($this->name, '\\{closure}')
            || $this->name === '{closure}';
    }
}
