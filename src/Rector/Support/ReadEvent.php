<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node\Expr;

/**
 * One step of a function body in evaluation order, as {@see ReadEventCollector} sees it.
 *
 * @internal
 */
final readonly class ReadEvent
{
    /** A read of a repeated expression (`$x->foo`, `$a['k']`) that may become a variable. */
    public const READ = 'read';

    /** A write to the expression itself, or a possible one (passed by reference). */
    public const WRITE = 'write';

    /** The base variable (`$x`, `$a`) is assigned, unset or may be changed by reference. */
    public const BASE_WRITE = 'base_write';

    /** User code may run here (a call, a magic method, `clone`, output): anything may change. */
    public const IMPURE = 'impure';

    /** Code the collector does not understand: nothing is known after it. */
    public const OPAQUE = 'opaque';

    /**
     * @param self::* $kind
     * @param non-empty-string|null $key The read or written expression ({@see ReadSpec::key()}).
     * @param non-empty-string|null $base The base variable without `$`.
     * @param int $statement Index of the top-level statement of the analyzed list.
     * @param bool $conditional Not executed every time the statement runs (a branch, `&&`, a loop body).
     */
    public function __construct(
        public string $kind,
        public ?string $key,
        public ?string $base,
        public int $statement,
        public bool $conditional,
        public ?Expr $node = null,
    ) {}
}
