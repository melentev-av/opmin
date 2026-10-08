<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

use PhpParser\Node;

/**
 * The user's "do not touch" marks on a function, method or class (brief, «Игнорирование кода»):
 * `#[\Opmin\Ignore]`, `#[\Opmin\Ignore(rules: ['fqn'])]`, `@opmin-ignore`, `@opmin-ignore fqn,isset` in
 * the docblock or in a plain comment right before the declaration (`// @opmin-ignore`).
 *
 * The attribute is recognized by its resolved name: the class need not exist in the project (PHP
 * does not validate attributes until `newInstance()`).
 *
 * @internal
 */
final class IgnoreMarks
{
    /**
     * Whether the node is excluded for a rule known by any of `$names` (lower-case alias, short class
     * name); with no names — excluded for every rule.
     *
     * @param list<string> $names
     */
    public static function ignored(Node $node, array $names = []): bool
    {
        $names = \array_map('strtolower', $names);
        $matches = static fn(?array $rules): bool => $rules === null || $names === [] || \array_intersect($names, $rules) !== [];
        $groups = match (true) {
            $node instanceof Node\FunctionLike => $node->getAttrGroups(),
            $node instanceof Node\Stmt\ClassLike => $node->attrGroups,
            default => [],
        };
        if ($groups !== []) {
            foreach ($groups as $group) {
                foreach ($group->attrs as $attribute) {
                    if (\strtolower(\ltrim($attribute->name->toString(), '\\')) === 'opmin\ignore' && $matches(self::attributeRules($attribute))) {
                        return true;
                    }
                }
            }
        }

        # The comments php-parser attaches to a declaration are the ones right before it: the docblock and `//`, `#`, `/* */`.
        foreach ($node->getComments() as $comment) {
            if (\preg_match_all('/@opmin-ignore\b[ \t]*([\w, \t]*)/', $comment->getText(), $found, \PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($found as $m) {
                $rules = \array_values(\array_filter(\array_map('trim', \explode(',', \strtolower($m[1])))));
                if ($matches($rules === [] ? null : $rules)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a function key matches one of the `ignore.functions` patterns (`App\Utils\*`).
     *
     * @param list<string> $patterns
     */
    public static function byConfig(array $patterns, string $key): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '~^' . \str_replace('\*', '.*', \preg_quote(\ltrim(\str_replace('\\\\', '\\', $pattern), '\\'), '~')) . '$~i';
            if (\preg_match($regex, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null null — every rule.
     */
    private static function attributeRules(Node\Attribute $attribute): ?array
    {
        foreach ($attribute->args as $arg) {
            if (($arg->name === null || $arg->name->toString() === 'rules') && $arg->value instanceof Node\Expr\Array_) {
                $rules = [];
                foreach ($arg->value->items as $item) {
                    $value = $item?->value;
                    $value instanceof Node\Scalar\String_ and $rules[] = \strtolower($value->value);
                }

                # `rules: []` is the attribute's default: every rule.
                return $rules === [] ? null : $rules;
            }
        }

        return null;
    }
}
