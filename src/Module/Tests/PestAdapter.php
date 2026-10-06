<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

use Opmin\Module\Project\Project;

/**
 * Pest runs on PHPUnit: the same JUnit report, `--filter` and coverage XML; tests are named by their
 * descriptions.
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
