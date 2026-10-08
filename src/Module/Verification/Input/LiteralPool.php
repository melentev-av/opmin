<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Input;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Constants of the function body, of both versions (phase 2 of input generation): strings, numbers, array keys,
 * `match`/`switch` conditions, `in_array` haystacks — plus their neighbours (`n-1`, `n+1`, a string
 * with a prefix or a suffix). Branches like `if ($x === 'magic')` are found this way, not by chance.
 *
 * @internal
 */
final class LiteralPool
{
    private const LIMIT = 64;

    /** @var list<int> */
    public array $ints = [];

    /** @var list<float> */
    public array $floats = [];

    /** @var list<string> */
    public array $strings = [];

    /** @var list<int|string> Array keys used in the body (`$a['x']`, `['x' => ...]`). */
    public array $keys = [];

    /**
     * @param Node\FunctionLike ...$functions Versions of the function: a constant only the changed
     *        version has is exactly where it may behave differently.
     */
    public static function collect(Node\FunctionLike ...$functions): self
    {
        $pool = new self();
        foreach ($functions as $function) {
            $pool->walk($function instanceof Node\PropertyHook ? $function->body : $function->getStmts());
            $function instanceof Expr\ArrowFunction and $pool->walk($function->expr);
        }

        $pool->finish();

        return $pool;
    }

    /**
     * Adds numbers to the pool (and their neighbours), e.g. timestamps around the fake clock for a
     * function that reads the time: a deadline compared with `time()` is decided there.
     *
     * @param list<int> $values
     */
    public function addInts(array $values): self
    {
        foreach ($values as $value) {
            $this->int($value);
        }

        $this->ints = \array_values(\array_unique($this->ints));

        return $this;
    }

    /**
     * Keys worth putting into generated arrays: keys of the body, then its strings and numbers
     * (`array_key_exists('x', $a)` names the key in a string).
     *
     * @return list<int|string>
     */
    public function keyCandidates(): array
    {
        $result = $this->keys;
        foreach ($this->strings as $s) {
            \strlen($s) <= 32 && \preg_match('/^-?\d+$/', $s) !== 1 and $result[] = $s;
        }

        \array_push($result, ...\array_slice($this->ints, 0, 8));

        return \array_slice(\array_values(\array_unique($result, \SORT_REGULAR)), 0, 24);
    }

    public function isEmpty(): bool
    {
        return $this->ints === [] && $this->floats === [] && $this->strings === [] && $this->keys === [];
    }

    /**
     * Literals that fit a type, as recipes.
     *
     * @return list<array<string, mixed>>
     */
    public function recipesFor(TypeSpec $type): array
    {
        $kinds = $type->scalarKinds();
        $result = [];
        if (\in_array(TypeSpec::STRING, $kinds, true)) {
            foreach ($this->strings as $s) {
                $result[] = Recipes::string($s);
            }

            # Numbers compared as strings: `$x === '42'`.
            foreach ($this->ints as $i) {
                $result[] = Recipes::string((string) $i);
            }
        }

        if (\in_array(TypeSpec::INT, $kinds, true)) {
            foreach ($this->ints as $i) {
                $result[] = Recipes::int($i);
            }

            foreach ($this->strings as $s) {
                \preg_match('/^-?\d{1,18}$/', $s) === 1 and $result[] = Recipes::int((int) $s);
            }
        }

        if (\in_array(TypeSpec::FLOAT, $kinds, true)) {
            foreach ($this->floats as $f) {
                $result[] = Recipes::float($f);
            }

            foreach (\array_slice($this->ints, 0, 8) as $i) {
                $result[] = Recipes::float((float) $i);
            }
        }

        return self::unique($result);
    }

    /**
     * @param list<array<string, mixed>> $recipes
     * @return list<array<string, mixed>>
     */
    private static function unique(array $recipes): array
    {
        $seen = [];
        $result = [];
        foreach ($recipes as $recipe) {
            $key = (string) \json_encode($recipe);
            isset($seen[$key]) or $result[] = $recipe;
            $seen[$key] = true;
        }

        return $result;
    }

    private function walk(mixed $node): void
    {
        if (\is_array($node)) {
            /** @var mixed $child */
            foreach ($node as $child) {
                $this->walk($child);
            }

            return;
        }

        if (!$node instanceof Node || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\Function_ || $node instanceof Stmt\ClassLike
        ) {
            return;
        }

        match (true) {
            $node instanceof Scalar\String_ => $this->string($node->value),
            $node instanceof Scalar\Int_ => $this->int($node->value),
            $node instanceof Scalar\Float_ => $this->floats[] = $node->value,
            $node instanceof Expr\UnaryMinus && $node->expr instanceof Scalar\Int_ => $this->int(-$node->expr->value),
            $node instanceof Expr\UnaryMinus && $node->expr instanceof Scalar\Float_ => $this->floats[] = -$node->expr->value,
            $node instanceof Expr\ArrayDimFetch && $node->dim instanceof Scalar\String_ => $this->keys[] = $node->dim->value,
            $node instanceof Expr\ArrayDimFetch && $node->dim instanceof Scalar\Int_ => $this->keys[] = $node->dim->value,
            $node instanceof Node\ArrayItem && $node->key instanceof Scalar\String_ => $this->keys[] = $node->key->value,
            $node instanceof Node\ArrayItem && $node->key instanceof Scalar\Int_ => $this->keys[] = $node->key->value,
            default => null,
        };

        foreach ($node->getSubNodeNames() as $name) {
            $name === 'attrGroups' or $this->walk($node->{$name});
        }
    }

    private function string(string $value): void
    {
        $this->strings[] = $value;
        if ($value !== '' && \strlen($value) <= 64) {
            # Neighbours: a prefix or a suffix catches `str_starts_with`, `===` against a near miss.
            $this->strings[] = $value . 'x';
            $this->strings[] = 'x' . $value;
            \strlen($value) > 1 and $this->strings[] = \substr($value, 0, -1);
            \strtoupper($value) === $value or $this->strings[] = \strtoupper($value);
        }
    }

    private function int(int $value): void
    {
        $this->ints[] = $value;
        $value > \PHP_INT_MIN and $this->ints[] = $value - 1;
        $value < \PHP_INT_MAX and $this->ints[] = $value + 1;
    }

    private function finish(): void
    {
        $this->ints = \array_slice(\array_values(\array_unique($this->ints)), 0, self::LIMIT);
        $this->floats = \array_slice(\array_values(\array_unique($this->floats, \SORT_REGULAR)), 0, self::LIMIT);
        $this->strings = \array_slice(\array_values(\array_unique($this->strings)), 0, self::LIMIT);
        $this->keys = \array_slice(\array_values(\array_unique($this->keys, \SORT_REGULAR)), 0, self::LIMIT);
    }
}
