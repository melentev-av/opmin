<?php

declare(strict_types=1);

namespace Opmin\Module\Check;

/**
 * A Stage A rule that, applied alone in a dry run, takes opcodes of a grown function back.
 *
 * Not verified: `opmin optimize` proves the change before keeping it.
 *
 * @internal
 */
final readonly class Suggestion
{
    /**
     * @param class-string $rule
     * @param positive-int $gain
     */
    public function __construct(
        public string $rule,
        public int $gain,
    ) {}

    public function describe(): string
    {
        return "{$this->shortName()} would save {$this->gain} (opmin optimize --rector-rule='{$this->rule}')";
    }

    public function shortName(): string
    {
        $at = \strrpos($this->rule, '\\');

        return $at === false ? $this->rule : \substr($this->rule, $at + 1);
    }

    /**
     * @return array{rule: class-string, gain: positive-int}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'gain' => $this->gain];
    }
}
