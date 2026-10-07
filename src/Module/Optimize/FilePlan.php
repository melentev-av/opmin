<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

/**
 * What a step keeps of the changes in one file: the candidate content and the functions in it.
 *
 * @internal
 */
final class FilePlan
{
    public string $candidate;

    /** @var array<non-empty-string, int> Kept top-level functions => opcodes saved. */
    public array $accepted = [];

    /** @var array<non-empty-string, string> Kept top-level functions => how the verifier proved them. */
    public array $statuses = [];

    /** @var array<non-empty-string, list<array<string, mixed>>> Kept top-level functions => {@see StepReport::check()} of each verified function. */
    public array $checks = [];

    /** A change outside functions: the file is kept or rolled back as a whole. */
    public bool $atomic = false;

    public function __construct(
        public readonly string $current,
        public readonly Units $before,
        public readonly Units $after,
    ) {
        $this->candidate = $current;
    }
}
