<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

use Opmin\Module\Tests\TestResult;

/**
 * Verification of a changed file on all three levels: syntax and static analysis, the project's
 * tests, differential tests per changed function.
 *
 * @internal
 */
final readonly class CandidateReport
{
    /**
     * @param list<FunctionResult> $functions
     * @param list<array{file: string, message: string, identifier: string, line: int}> $phpstan New PHPStan errors.
     * @param list<string> $notes What was not checked and why (no PHPStan, no coverage driver…).
     */
    public function __construct(
        public array $functions,
        public ?string $syntaxError = null,
        public array $phpstan = [],
        public ?string $runner = null,
        public ?TestResult $tests = null,
        public array $notes = [],
    ) {}

    public function accepted(): bool
    {
        if ($this->syntaxError !== null || $this->phpstan !== [] || ($this->tests !== null && !$this->tests->success)) {
            return false;
        }

        foreach ($this->functions as $function) {
            if (!$function->accepted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $functions = [];
        foreach ($this->functions as $function) {
            $functions[$function->key] = $function->toArray();
        }

        return [
            'accepted' => $this->accepted(),
            'syntax_error' => $this->syntaxError,
            'phpstan_new_errors' => $this->phpstan,
            'tests' => $this->tests === null ? null : [
                'runner' => $this->runner,
                'success' => $this->tests->success,
                'tests' => $this->tests->tests,
                'failed' => $this->tests->failed,
            ],
            'functions' => $functions,
            'notes' => $this->notes,
        ];
    }
}
