<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Locate;

use Opmin\Module\Analysis\Flag;
use PhpParser\Node;

/**
 * A function-like unit of code that OPcache compiles into its own op_array.
 *
 * @internal
 */
final readonly class CodeUnit
{
    /**
     * @param non-empty-string $key Stable key: `App\Foo::bar`, `App\Foo::bar::{closure:2}`,
     *        `App\Foo::bar::{class:1}::baz`, `App\Foo::$name::get`, `src/routes.php::<main>`.
     * @param non-empty-string|null $dumpName Expected dump header: `$_main`, `App\fn`, `App\Foo::bar`,
     *        `App\Foo::$name::get`; null for closures (their names differ between PHP versions). For
     *        methods of anonymous classes — `@anonymous::<method>`: the class part of the header
     *        (`class@anonymous`, `Parent@anonymous`) is not predictable.
     * @param bool $abstract Abstract or interface method/hook: no useful opcodes, never reported
     *        (PHP 8.1 still dumps them, 8.2+ does not).
     * @param list<Flag> $flags Flags of the dynamic scope detector found in the unit's own body.
     * @param Node\FunctionLike|null $node The AST of the unit (null for main code).
     * @param Node\Stmt\ClassLike|null $class The class of a method or hook, or the class a closure is defined in.
     * @param int|null $dumpEndLine The last line in the dump when it is not {@see self::$endLine}: PHP ends an
     *        arrow function on the line of the token after its body (the lookahead of its parser).
     */
    public function __construct(
        public string $key,
        public UnitKind $kind,
        public ?string $dumpName,
        public int $line,
        public int $endLine,
        public bool $abstract = false,
        public array $flags = [],
        public ?Node\FunctionLike $node = null,
        public ?Node\Stmt\ClassLike $class = null,
        public ?int $dumpEndLine = null,
    ) {}

    public function isAnonymousClassMethod(): bool
    {
        return $this->dumpName !== null && \str_starts_with($this->dumpName, '@anonymous::');
    }
}
