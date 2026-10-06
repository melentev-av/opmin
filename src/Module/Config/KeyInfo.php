<?php

declare(strict_types=1);

namespace Opmin\Module\Config;

use Opmin\Module\Common\Internal\Attribute\ConfigKey;

/**
 * Everything known about one key of `opmin.yaml`, read from the DTO property that declares it.
 *
 * @internal
 */
final class KeyInfo
{
    /**
     * @param non-empty-string $type Builtin type name (`string`, `int`, `float`, `bool`, `array`)
     *        or a backed enum class.
     * @param class-string $class DTO class declaring the key.
     * @param non-empty-string $property Property name in the DTO.
     */
    public function __construct(
        public readonly ConfigKey $key,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly mixed $default,
        public readonly string $class,
        public readonly string $property,
    ) {}

    /**
     * @return class-string<\BackedEnum>|null
     */
    public function enum(): ?string
    {
        if (!\enum_exists($this->type) || !\is_subclass_of($this->type, \BackedEnum::class)) {
            return null;
        }

        /** @var class-string<\BackedEnum> */
        return $this->type;
    }

    /**
     * Human-readable description of the expected value, for error messages.
     */
    public function expected(): string
    {
        $enum = $this->enum();
        $expected = match (true) {
            $enum !== null => 'one of ' . \implode(' | ', \array_map(
                static fn(\BackedEnum $case): string => (string) $case->value,
                $enum::cases(),
            )),
            $this->type === 'array' && $this->key->list => 'a list of strings',
            $this->type === 'array' => 'a map',
            default => $this->type,
        };

        return $this->nullable ? $expected . ' or null' : $expected;
    }
}
