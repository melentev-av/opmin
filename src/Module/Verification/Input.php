<?php

declare(strict_types=1);

namespace Opmin\Module\Verification;

/**
 * One input of a differential test: recipes of the arguments, of `$this` and of the `use` variables
 * of a closure, and the caller's typing mode.
 *
 * Recipes are plain arrays of the harness protocol (`docs/harness-protocol.md`), see {@see Recipe}:
 * the input is built by the harness under `php.binary`, never in the PHP running opmin.
 *
 * @psalm-type RecipeArray = array<string, mixed>
 * @internal
 */
final readonly class Input
{
    /**
     * @param list<RecipeArray> $args Positional arguments.
     * @param RecipeArray|null $receiver `$this` of an instance method or of a bound closure.
     * @param array<non-empty-string, RecipeArray> $uses Values of the `use` variables of a closure.
     * @param bool $strict Call from a `strict_types=1` file (the caller decides the typing mode).
     */
    public function __construct(
        public array $args,
        public ?array $receiver = null,
        public array $uses = [],
        public bool $strict = false,
    ) {}

    /**
     * @param array<array-key, mixed> $data As produced by {@see self::toArray()}.
     */
    public static function fromArray(array $data): self
    {
        /** @var array{args?: list<RecipeArray>, this?: RecipeArray|null, uses?: array<non-empty-string, RecipeArray>, strict?: bool} $data */
        return new self(
            args: $data['args'] ?? [],
            receiver: $data['this'] ?? null,
            uses: $data['uses'] ?? [],
            strict: $data['strict'] ?? false,
        );
    }

    /**
     * @return array{args: list<RecipeArray>, this: RecipeArray|null, uses: array<non-empty-string, RecipeArray>, strict: bool}
     */
    public function toArray(): array
    {
        return ['args' => $this->args, 'this' => $this->receiver, 'uses' => $this->uses, 'strict' => $this->strict];
    }

    /**
     * @param list<RecipeArray> $args
     */
    public function withArgs(array $args): self
    {
        return new self($args, $this->receiver, $this->uses, $this->strict);
    }

    /**
     * @param RecipeArray|null $receiver
     */
    public function withReceiver(?array $receiver): self
    {
        return new self($this->args, $receiver, $this->uses, $this->strict);
    }

    /**
     * @param array<non-empty-string, RecipeArray> $uses
     */
    public function withUses(array $uses): self
    {
        return new self($this->args, $this->receiver, $uses, $this->strict);
    }

    public function withStrict(bool $strict): self
    {
        return new self($this->args, $this->receiver, $this->uses, $strict);
    }
}
