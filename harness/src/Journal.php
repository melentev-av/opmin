<?php

declare(strict_types=1);

namespace Opmin\Harness;

/**
 * Calls of mocks and callable arguments during one `call`, in order: the order of side effects is
 * part of the behavior.
 *
 * @internal
 */
final class Journal
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public function __construct(
        private Describer $describer,
    ) {}

    /**
     * @param list<mixed> $args
     */
    public function record(?int $id, string $method, array $args): void
    {
        $this->entries[] = ['mock' => $id, 'method' => $method, 'args' => $this->describer->describeList($args)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
