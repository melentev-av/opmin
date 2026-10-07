<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis\Shadow;

/**
 * What one file declares or mocks that can shadow a global function or constant (brief, 2.3).
 * Collected by {@see ShadowCollector}.
 *
 * Function names and namespaces are lower-case (PHP resolves them case-insensitively); a constant is
 * `lower-case namespace\Name` (the namespace part is case-insensitive, the name is not). Everything
 * is fully qualified without the leading `\`; a global symbol has no namespace part.
 *
 * @internal
 */
final readonly class FileShadows
{
    /**
     * @param list<lowercase-string> $functions Declared functions (`app\strlen`, `helper`).
     * @param list<non-empty-string> $constants Declared constants (`define()`, `const`).
     * @param list<array{lowercase-string|null, lowercase-string|null}> $mocks Functions mocked at runtime
     *        (php-mock, ClockMock, DnsMock, `eval`): [namespace, function], null — unknown, i.e. any.
     */
    public function __construct(
        public array $functions = [],
        public array $constants = [],
        public array $mocks = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{functions: list<lowercase-string>, constants: list<non-empty-string>, mocks: list<array{lowercase-string|null, lowercase-string|null}>} $data */
        return new self($data['functions'], $data['constants'], $data['mocks']);
    }

    public function isEmpty(): bool
    {
        return $this->functions === [] && $this->constants === [] && $this->mocks === [];
    }

    /**
     * @return array{functions: list<lowercase-string>, constants: list<non-empty-string>, mocks: list<array{lowercase-string|null, lowercase-string|null}>}
     */
    public function toArray(): array
    {
        return ['functions' => $this->functions, 'constants' => $this->constants, 'mocks' => $this->mocks];
    }
}
