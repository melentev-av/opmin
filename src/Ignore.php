<?php

declare(strict_types=1);

namespace Opmin;

/**
 * Keeps a function, a method or every method of a class away from opmin: `#[\Opmin\Ignore]` for every
 * rule, `#[\Opmin\Ignore(rules: ['fqn', 'llm'])]` for the given ones (aliases or short class names of
 * the rules, see the README).
 *
 * opmin recognizes the attribute by its name in the source, so the class need not be installed in the
 * project: PHP does not check an attribute until `ReflectionAttribute::newInstance()`. This class is
 * for IDEs and static analysis; it is never scoped in the PHAR.
 *
 * Without the dependency: `// @opmin-ignore [rules]` right before the declaration or `@opmin-ignore
 * [rules]` in its docblock.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Ignore
{
    /**
     * @param list<non-empty-string> $rules Rules to keep away; empty — every rule.
     */
    public function __construct(
        public array $rules = [],
    ) {}
}
