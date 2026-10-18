<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Rector;

/**
 * One rule of Stage A as it is applied: alone, with its options, or taken out of a set.
 *
 * @internal
 */
final readonly class RuleSpec
{
    /**
     * @param class-string $class
     * @param array<string, mixed>|null $options Options of a configurable rule; null — not configurable.
     * @param non-empty-string|null $set A standard set the rule comes from: the set is imported (it may
     *        configure the rule) with every other rule of it skipped.
     * @param list<class-string> $skip The other rules of the set.
     * @param bool $custom One of opmin's own rules.
     * @param Gain $gain What the rule saves: how its changes are accepted.
     */
    public function __construct(
        public string $class,
        public ?array $options = null,
        public ?string $set = null,
        public array $skip = [],
        public bool $custom = false,
        public Gain $gain = Gain::Opcodes,
    ) {}

    /**
     * @param array{class: class-string, options?: array<string, mixed>|null, set?: non-empty-string|null, skip?: list<class-string>, custom?: bool, gain?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['options'] ?? null,
            $data['set'] ?? null,
            $data['skip'] ?? [],
            $data['custom'] ?? false,
            Gain::from($data['gain'] ?? Gain::Opcodes->value),
        );
    }

    /**
     * @return non-empty-string
     */
    public function shortName(): string
    {
        $at = \strrpos($this->class, '\\');
        $name = $at === false ? $this->class : \substr($this->class, $at + 1);

        return $name === '' ? $this->class : $name;
    }

    /**
     * Short name for ignore marks (`fqn`), when the rule has one.
     */
    public function alias(): ?string
    {
        if (!\method_exists($this->class, 'alias')) {
            return null;
        }

        /** @var mixed $alias */
        $alias = \call_user_func([$this->class, 'alias']);

        return \is_string($alias) ? $alias : null;
    }

    /**
     * @return array{class: class-string, options: array<string, mixed>|null, set: non-empty-string|null, skip: list<class-string>, custom: bool, gain: string}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'options' => $this->options,
            'set' => $this->set,
            'skip' => $this->skip,
            'custom' => $this->custom,
            'gain' => $this->gain->value,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        return new self($this->class, $options, $this->set, $this->skip, $this->custom, $this->gain);
    }
}
