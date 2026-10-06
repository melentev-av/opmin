<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Target;

use Opmin\Module\Analysis\Flag;
use Opmin\Module\Opcode\Locate\UnitKind;
use PhpParser\Node;

/**
 * One version of the function under test, ready for the harness: how to call it, what it needs
 * (`$this`, generated wrapper code for closures and trait methods), its AST and flags.
 *
 * @internal
 */
final readonly class Target
{
    /**
     * @param non-empty-string $key Function key ({@see \Opmin\Module\Opcode\Locate\CodeUnit::$key}).
     * @param array<string, mixed> $call The `target` of the harness protocol.
     * @param string|null $receiver Class of `$this` when a call needs one.
     * @param string|null $wrapper PHP code of a generated file the harness must load (closure wrapper,
     *        a class using a trait); lines of the function are kept.
     * @param list<Flag> $flags
     */
    public function __construct(
        public string $key,
        public UnitKind $kind,
        public array $call,
        public string $namespace,
        public Node\FunctionLike $node,
        public ?string $receiver,
        public ?string $wrapper,
        public array $flags,
    ) {}
}
