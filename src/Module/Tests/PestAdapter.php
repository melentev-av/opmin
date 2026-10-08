<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Opmin\Module\Project\Project;

/**
 * Pest runs on PHPUnit: the same JUnit report, `--filter` and coverage XML. A test is a method
 * `__pest_evaluable_<description>` of a generated class `P\Tests\FooTest`, and that is how coverage
 * names it; `--filter` however matches `Tests\FooTest::<description>` (the class without `P\`).
 *
 * @internal
 */
final class PestAdapter extends PhpUnitAdapter
{
    public static function detect(Project $project): bool
    {
        return $project->root->join('vendor/bin/pest')->isFile()
            || $project->root->join('tests/Pest.php')->isFile()
            || self::requires($project, 'pestphp/pest');
    }

    /**
     * `--filter` regex from coverage ids (`P\Tests\FooTest::__pest_evaluable_sums_numbers#dataset "big"`):
     * the description is restored as a pattern — the evaluable name replaced every character outside
     * `[A-Za-z0-9]` by `_` and doubled the description's own `_`.
     *
     * @param non-empty-list<non-empty-string> $testIds
     */
    public static function filter(array $testIds): string
    {
        $alternatives = [];
        $other = [];
        foreach ($testIds as $id) {
            if (\preg_match('/^(?:P\\\\)?(.+?)::__pest_evaluable_(.+?)(?:#(.+))?$/s', $id, $m) !== 1) {
                $other[] = $id;
                continue;
            }

            $description = '';
            $parts = \preg_split('/(_+)/', $m[2], -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY);
            foreach ($parts === false ? [] : $parts as $part) {
                $description .= $part[0] === '_'
                    ? \str_repeat('(?:_|[^A-Za-z0-9\x80-\xff])', \intdiv(\strlen($part), 2)) . (\strlen($part) % 2 === 1 ? '[^A-Za-z0-9\x80-\xff]' : '')
                    : \preg_quote($part, '/');
            }

            $set = isset($m[3]) && $m[3] !== ''
                ? ' with data set "' . \preg_quote($m[3], '/') . '"'
                : '(?: with data set .*)?';
            $alternatives[] = '(?:P\\\\)?' . \preg_quote($m[1], '/') . '::' . $description . $set;
        }

        # Ids of plain PHPUnit test classes run by Pest.
        \array_push($alternatives, ...self::alternatives($other));

        return '/^(?:' . \implode('|', $alternatives) . ')$/i';
    }

    public function name(): string
    {
        return 'pest';
    }

    public function counterexampleTest(string $name, string $description, array $setup, string $call, ?string $expected, ?array $exception, ?string $output, bool $strict = false): string
    {
        $body = self::indent($setup, 4);
        $body .= "\n    \$run = static fn() => {$call};\n";
        if ($exception !== null) {
            $body .= "\n    expect(\$run)->toThrow(\\{$exception['class']}::class, " . \var_export($exception['message'], true) . ");\n";
        } elseif ($expected !== null) {
            $body .= "\n    expect(\$run())->toBe({$expected});\n";
        } else {
            $body .= "\n    \$run();\n    \$this->markTestIncomplete('The original result is not a plain value, see the report.');\n";
        }

        $output === null or $body .= '    $this->expectOutputString(' . \var_export($output, true) . ");\n";

        return "<?php\n\n" . self::strictTypes($strict) . "// " . \str_replace("\n", "\n// ", $description) . "\n"
            . 'test(' . \var_export("{$name}: behavior is kept", true) . ", function () {\n{$body}});\n";
    }

    protected function command(): array
    {
        return CommandLine::withPhp(CommandLine::split($this->binary ?? 'vendor/bin/pest'), $this->php, $this->project->root);
    }
}
