<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

use Opmin\Module\Common\Internal\DidYouMean;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

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
    /** A mark in a comment: `@opmin-ignore`, optionally followed by names (aliases, short or full class names). */
    private const COMMENT = '/@opmin-ignore\b[ \t]*([\w\\\\, \t]*)/';

    /**
     * Whether the node is excluded for a rule known by any of `$names` (lower-case alias, short class
     * name); with no names — excluded for every rule. A full class name in a mark counts as its short
     * name, in both forms.
     *
     * @param list<string> $names
     */
    public static function ignored(Node $node, array $names = []): bool
    {
        $names = \array_map('strtolower', $names);
        foreach (self::marks($node) as [$rules]) {
            if ($rules === null || $names === [] || \array_intersect($names, $rules) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names in the marks of a file that match none of `$known`: such a mark excludes nothing, so a
     * typo would silently let opmin change the code the user asked it not to touch. Not an error: a
     * mark may name a standard Rector rule that is not enabled in this run.
     *
     * @param list<string> $known Lower-case aliases and short class names.
     * @return list<array{line: int, mark: string, name: string}>
     */
    public static function unknown(string $code, array $known): array
    {
        if (\stripos($code, 'opmin') === false) {
            return [];
        }

        try {
            $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
        $nodes = (new NodeFinder())->find($stmts, static fn(Node $n): bool => $n instanceof Node\FunctionLike || $n instanceof Node\Stmt\ClassLike);
        $known = \array_flip(\array_map('strtolower', $known));
        $found = [];
        foreach ($nodes as $node) {
            foreach (self::marks($node) as [$rules, $line, $mark, $written]) {
                foreach ($rules ?? [] as $i => $name) {
                    isset($known[$name]) or $found["{$line}:{$name}"] = ['line' => $line, 'mark' => $mark, 'name' => $written[$i]];
                }
            }
        }

        return \array_values($found);
    }

    /**
     * {@see self::unknown()} as warnings for the user, with the closest known name.
     *
     * @param non-empty-string $relative Path of the file for the message.
     * @param list<string> $aliases Rule aliases (`fqn`, `llm`), listed in the hint.
     * @param list<string> $known Every name a mark may use: the aliases and the short class names of the rules.
     * @return list<string>
     */
    public static function warnings(string $code, string $relative, array $aliases, array $known): array
    {
        $warnings = [];
        foreach (self::unknown($code, [...$aliases, ...$known]) as $mark) {
            $short = self::normalize([$mark['name']])[0] ?? $mark['name'];
            $warnings[] = \sprintf(
                '%s:%d: `%s` lists `%s`, which names no known rule, so it excludes nothing.%s Known names: %s, or the short class name of a rule.',
                $relative,
                $mark['line'],
                $mark['mark'],
                $mark['name'],
                DidYouMean::phrase(DidYouMean::closest($short, [...$aliases, ...$known])),
                \implode(', ', $aliases),
            );
        }

        return $warnings;
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
     * Every mark on the node: the names it lists (normalized; null — every rule), its line, its
     * form and the names as written.
     *
     * @return list<array{list<string>|null, int, string, list<string>}>
     */
    private static function marks(Node $node): array
    {
        $marks = [];
        $groups = match (true) {
            $node instanceof Node\FunctionLike => $node->getAttrGroups(),
            $node instanceof Node\Stmt\ClassLike => $node->attrGroups,
            default => [],
        };
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if (\strtolower(\ltrim($attribute->name->toString(), '\\')) === 'opmin\\ignore') {
                    $written = self::attributeRules($attribute);
                    $marks[] = [self::normalize($written), $attribute->getStartLine(), '#[\\Opmin\\Ignore]', $written ?? []];
                }
            }
        }

        # The comments php-parser attaches to a declaration are the ones right before it: the docblock and `//`, `#`, `/* */`.
        foreach ($node->getComments() as $comment) {
            if (\preg_match_all(self::COMMENT, $comment->getText(), $found, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($found as $m) {
                $written = \array_values(\array_filter(\array_map('trim', \explode(',', $m[1][0])), static fn(string $s): bool => $s !== ''));
                $line = $comment->getStartLine() + \substr_count($comment->getText(), "\n", 0, $m[0][1]);
                $marks[] = [self::normalize($written === [] ? null : $written), $line, '@opmin-ignore', $written];
            }
        }

        return $marks;
    }

    /**
     * Lower case; a full class name (`\Opmin\Rector\Rule\FullyQualifyGlobalCallsRector`) is reduced to its
     * short name, the form a rule is matched by.
     *
     * @param list<string>|null $names
     * @return list<string>|null
     */
    private static function normalize(?array $names): ?array
    {
        if ($names === null) {
            return null;
        }

        return \array_map(static function (string $name): string {
            $name = \strtolower(\trim($name, '\\'));
            $at = \strrpos($name, '\\');

            return $at === false ? $name : \substr($name, $at + 1);
        }, $names);
    }

    /**
     * The names as written; null — every rule.
     *
     * @return list<string>|null
     */
    private static function attributeRules(Node\Attribute $attribute): ?array
    {
        foreach ($attribute->args as $arg) {
            if (($arg->name === null || $arg->name->toString() === 'rules') && $arg->value instanceof Node\Expr\Array_) {
                $rules = [];
                foreach ($arg->value->items as $item) {
                    $value = $item?->value;
                    $value instanceof Node\Scalar\String_ and $rules[] = $value->value;
                }

                # `rules: []` is the attribute's default: every rule.
                return $rules === [] ? null : $rules;
            }
        }

        return null;
    }
}
