<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

/**
 * Calls that surely reach a global function, with plain positional arguments.
 *
 * @internal
 */
final class GlobalFunctionCall
{
    /**
     * The lower-case name of the global function `$call` reaches: `\name()`, or `name()` imported with
     * `use function name;` or written in the global namespace. An unqualified call in a namespace may
     * reach a namespaced function at run time: {@see \Opmin\Rector\Rule\FullyQualifyGlobalCallsRector}
     * runs first and qualifies it when that is safe.
     */
    public static function name(FuncCall $call): ?string
    {
        if (!$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $name = $call->name->isFullyQualified() ? $call->name->toString() : (string) $call->name->getAttribute('resolvedName');

        return $name === '' || \str_contains($name, '\\') ? null : \strtolower($name);
    }

    /**
     * The call of the global function `$function` with only positional arguments, no spread.
     */
    public static function plain(FuncCall $call, string $function): bool
    {
        if (self::name($call) !== $function) {
            return false;
        }

        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack || $arg->name !== null || $arg->byRef) {
                return false;
            }
        }

        return true;
    }
}
