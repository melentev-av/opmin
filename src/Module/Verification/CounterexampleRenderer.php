<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Tests\TestRunnerAdapter;
use Opmin\Module\Verification\Input\Recipes;
use Opmin\Module\Verification\Target\Target;

/**
 * Turns a counterexample into a test of the project's runner: the input as PHP code, the call, and
 * what the original version returned, threw or printed — the test passes on the original code and
 * fails on the change.
 *
 * Inputs with mocks or callables, closures and hooks become incomplete tests carrying the input as
 * JSON: their PHP rendering would need the runner's own doubles.
 *
 * @psalm-type Recipe = array<string, mixed>
 * @internal
 */
final class CounterexampleRenderer
{
    /** @var list<string> */
    private array $setup = [];

    /** @var array<int, string> Variable per object id. */
    private array $variables = [];

    private bool $supported = true;

    /**
     * @param non-empty-string $name Class name of the test.
     */
    public function render(Target $target, Counterexample $counterexample, TestRunnerAdapter $runner, string $name): string
    {
        $this->setup = [];
        $this->variables = [];
        $this->supported = true;
        $input = $counterexample->input;
        $description = "opmin: the change of {$target->key} behaves differently on this input.\n"
            . "Difference: {$counterexample->difference}\n"
            . 'Input: ' . (string) \json_encode($input->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        $receiver = $input->receiver === null ? null : $this->expression($input->receiver);
        $args = [];
        foreach ($input->args as $i => $arg) {
            $this->setup[] = "\$arg{$i} = " . $this->expression($arg) . ';';
            $args[] = "\$arg{$i}";
        }

        $receiver === null or $this->setup[] = "\$subject = {$receiver};";
        $call = $this->call($target, \implode(', ', $args), $receiver !== null);
        /** @var list<array<string, mixed>> $calls */
        $calls = \is_array($counterexample->original['calls'] ?? null) ? $counterexample->original['calls'] : [];
        $first = $calls[0] ?? [];
        $status = $first['status'] ?? null;
        $expected = $status === 'returned' ? self::literal($first['value'] ?? null) : null;
        $exception = null;
        if ($status === 'threw' && \is_array($first['exception'] ?? null)) {
            /** @var array<string, mixed> $thrown */
            $thrown = $first['exception'];
            $exception = ['class' => (string) ($thrown['class'] ?? 'Throwable'), 'message' => (string) ($thrown['message'] ?? '')];
        }

        /** @var array<string, mixed>|null $printed */
        $printed = \is_array($first['output'] ?? null) ? $first['output'] : null;
        $output = $printed === null ? '' : Recipes::bytes($printed);
        /** @psalm-suppress TypeDoesNotContainType $this->supported is changed by expression() */
        if (!$this->supported || $call === null) {
            return $runner->counterexampleTest($name, $description . "\nThe input cannot be written as plain PHP: reproduce it with the JSON above.", [], 'null', null, null, null, $input->strict);
        }

        return $runner->counterexampleTest($name, $description, $this->setup, $call, $expected, $exception, $output === '' ? null : $output, $input->strict);
    }

    /**
     * A PHP literal of a plain result; null for objects, resources, NAN (not `===` to itself).
     */
    private static function literal(mixed $value): ?string
    {
        if (!\is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */

        return match ($value['type'] ?? null) {
            'null' => 'null',
            'bool' => ($value['value'] ?? false) === true ? 'true' : 'false',
            'int' => self::int((int) ($value['value'] ?? 0)),
            'float' => ($value['value'] ?? '') === 'NAN' ? null : self::float((string) ($value['value'] ?? '0.0')),
            'string' => self::string(Recipes::bytes($value)),
            'enum' => '\\' . \ltrim((string) ($value['class'] ?? ''), '\\') . '::' . (string) ($value['case'] ?? ''),
            'array' => self::arrayLiteral($value),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function arrayLiteral(array $value): ?string
    {
        $items = [];
        /** @var list<array{mixed, mixed}> $pairs */
        $pairs = \is_array($value['items'] ?? null) ? $value['items'] : [];
        foreach ($pairs as [$key, $item]) {
            $k = self::literal($key);
            $v = self::literal($item);
            if ($k === null || $v === null) {
                return null;
            }

            $items[] = "{$k} => {$v}";
        }

        return '[' . \implode(', ', $items) . ']';
    }

    private static function int(int $value): string
    {
        return $value === \PHP_INT_MIN ? '\PHP_INT_MIN' : (string) $value;
    }

    private static function float(string $value): string
    {
        return match ($value) {
            'NAN' => '\NAN',
            'INF' => '\INF',
            '-INF' => '-\INF',
            default => \str_contains($value, '.') || \str_contains($value, 'E') ? $value : $value . '.0',
        };
    }

    /**
     * A string literal; bytes outside printable UTF-8 are escaped.
     */
    private static function string(string $value): string
    {
        if (\preg_match('//u', $value) === 1 && \preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
            return \var_export($value, true);
        }

        $escaped = '';
        foreach (\str_split($value) as $byte) {
            $code = \ord($byte);
            $escaped .= match (true) {
                $byte === '"', $byte === '\\', $byte === '$' => '\\' . $byte,
                $code >= 0x20 && $code < 0x7f => $byte,
                default => \sprintf('\x%02x', $code),
            };
        }

        return '"' . $escaped . '"';
    }

    /**
     * @return non-empty-string|null
     */
    private function call(Target $target, string $args, bool $receiver): ?string
    {
        $call = $target->call;
        $kind = $call['kind'] ?? null;
        $name = (string) ($call['name'] ?? '');
        $class = '\\' . \ltrim((string) ($call['class'] ?? ''), '\\');
        $node = $target->node;
        $public = !$node instanceof \PhpParser\Node\Stmt\ClassMethod || $node->isPublic();

        return match (true) {
            $kind === 'function' => "\\{$name}({$args})",
            $kind === 'method' && \strtolower($name) === '__construct' => "new {$class}({$args})",
            $kind === 'method' && !$receiver => $public
                ? "{$class}::{$name}({$args})"
                : "\\Closure::bind(static fn() => static::{$name}({$args}), null, {$class}::class)()",
            $kind === 'method' => $public ? "\$subject->{$name}({$args})" : "(fn() => \$this->{$name}({$args}))->call(\$subject)",
            default => null,
        };
    }

    /**
     * @param Recipe $recipe
     */
    private function expression(array $recipe): string
    {
        $type = $recipe['type'] ?? null;
        $code = match ($type) {
            'null' => 'null',
            'bool' => ($recipe['value'] ?? false) === true ? 'true' : 'false',
            'int' => self::int((int) ($recipe['value'] ?? 0)),
            'float' => self::float((string) ($recipe['value'] ?? '0.0')),
            'string' => self::string(Recipes::bytes($recipe)),
            'array' => $this->array($recipe),
            'enum' => '\\' . \ltrim((string) ($recipe['class'] ?? ''), '\\') . '::' . (string) ($recipe['case'] ?? ''),
            'object' => $this->object($recipe),
            'ref' => $this->variables[(int) ($recipe['id'] ?? 0)] ?? 'null',
            default => $this->unsupported(),
        };

        if (isset($recipe['id']) && \in_array($type, ['object'], true)) {
            $variable = '$object' . (int) $recipe['id'];
            $this->setup[] = "{$variable} = {$code};";
            $this->variables[(int) $recipe['id']] = $variable;

            return $variable;
        }

        return $code;
    }

    /**
     * @param Recipe $recipe
     */
    private function array(array $recipe): string
    {
        $items = [];
        /** @var list<array{Recipe, Recipe}> $pairs */
        $pairs = \is_array($recipe['items'] ?? null) ? $recipe['items'] : [];
        foreach ($pairs as [$key, $value]) {
            $items[] = $this->expression($key) . ' => ' . $this->expression($value);
        }

        return '[' . \implode(', ', $items) . ']';
    }

    /**
     * @param Recipe $recipe
     */
    private function object(array $recipe): string
    {
        $class = '\\' . \ltrim((string) ($recipe['class'] ?? ''), '\\');
        if (($recipe['via'] ?? 'ctor') === 'ctor') {
            /** @var list<Recipe> $args */
            $args = \is_array($recipe['args'] ?? null) ? $recipe['args'] : [];

            return "new {$class}(" . \implode(', ', \array_map($this->expression(...), $args)) . ')';
        }

        $lines = ["(static function () {", "    \$object = (new \\ReflectionClass({$class}::class))->newInstanceWithoutConstructor();"];
        /** @var array<string, Recipe> $props */
        $props = \is_array($recipe['props'] ?? null) ? $recipe['props'] : [];
        foreach ($props as $name => $value) {
            $parts = \explode('::', $name, 2);
            [$scope, $property] = \count($parts) === 2 ? $parts : [$class, $name];
            $scope = '\\' . \ltrim($scope, '\\');
            $lines[] = "    \\Closure::bind(fn() => \$this->{$property} = " . $this->expression($value) . ", \$object, {$scope}::class)();";
        }

        $lines[] = '    return $object;';
        $lines[] = '})()';

        return \implode("\n", $lines);
    }

    private function unsupported(): string
    {
        $this->supported = false;

        return 'null';
    }
}
