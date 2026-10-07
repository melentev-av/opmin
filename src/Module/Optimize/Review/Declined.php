<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize\Review;

use Internal\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Changes the user declined in `opmin optimize --review`: `rejected` of `opmin.baseline.yaml` in the
 * project root (brief, «Читаемость»). Such a change of a function by a rule is never proposed again,
 * in both stages (the rule of the LLM stage is `llm`). The file is meant to be committed; it can be
 * edited by hand.
 *
 * ```yaml
 * rejected:
 *   - { function: 'App\Cart::total', rule: fqn }
 * ```
 *
 * @internal
 */
final class Declined
{
    public const FILE = 'opmin.baseline.yaml';

    /** @var list<array{function: non-empty-string, rule: non-empty-string}> */
    private array $rejected;

    /** @var array<string, mixed> The rest of the file, kept as it is. */
    private array $other;

    private bool $changed = false;

    /**
     * @param list<array{function: non-empty-string, rule: non-empty-string}> $rejected
     * @param array<string, mixed> $other
     */
    private function __construct(
        private readonly ?Path $file,
        array $rejected,
        array $other,
    ) {
        $this->rejected = $rejected;
        $this->other = $other;
    }

    /**
     * Nothing declined, nothing saved.
     */
    public static function none(): self
    {
        return new self(null, [], []);
    }

    /**
     * @throws \InvalidArgumentException On a file that is not valid.
     */
    public static function load(Path $root): self
    {
        $file = $root->join(self::FILE);
        if (!$file->exists()) {
            return new self($file, [], []);
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile((string) $file);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(self::FILE . ': ' . $e->getMessage(), previous: $e);
        }

        if ($parsed !== null && (!\is_array($parsed) || ($parsed !== [] && \array_is_list($parsed)))) {
            throw new \InvalidArgumentException(self::FILE . ': expected a mapping with `rejected`.');
        }

        /** @var array<string, mixed> $data */
        $data = $parsed ?? [];

        /** @var mixed $list */
        $list = $data['rejected'] ?? [];
        \is_array($list) && \array_is_list($list) or throw new \InvalidArgumentException(self::FILE . ': `rejected` must be a list.');
        $rejected = [];
        /** @var mixed $entry */
        foreach ($list as $i => $entry) {
            /** @var mixed $function */
            $function = \is_array($entry) ? ($entry['function'] ?? null) : null;
            /** @var mixed $rule */
            $rule = \is_array($entry) ? ($entry['rule'] ?? null) : null;
            $function = \is_string($function) ? \ltrim($function, '\\') : '';
            $function !== '' && \is_string($rule) && $rule !== '' or throw new \InvalidArgumentException(
                self::FILE . ": `rejected.{$i}` must have non-empty `function` and `rule`.",
            );
            $rejected[] = ['function' => $function, 'rule' => $rule];
        }

        unset($data['rejected']);

        /** @var array<string, mixed> $data */
        return new self($file, $rejected, $data);
    }

    /**
     * Whether the change of `$function` by a rule known by any of `$rules` (alias, short or full class
     * name; case-insensitive) was declined.
     *
     * @param list<string> $rules
     */
    public function has(string $function, array $rules): bool
    {
        $function = \strtolower(\ltrim($function, '\\'));
        $rules = \array_map(static fn(string $r): string => \strtolower(\ltrim($r, '\\')), $rules);
        foreach ($this->rejected as $entry) {
            if (\strtolower($entry['function']) === $function && \in_array(\strtolower(\ltrim($entry['rule'], '\\')), $rules, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-string $function
     * @param non-empty-string $rule
     */
    public function add(string $function, string $rule): void
    {
        if ($this->has($function, [$rule])) {
            return;
        }

        $function = \ltrim($function, '\\');
        $function === '' or $this->rejected[] = ['function' => $function, 'rule' => $rule];
        $this->changed = true;
    }

    /**
     * Writes the file when something was added: entries sorted, other keys kept.
     *
     * @return Path|null The file when it was written.
     */
    public function save(): ?Path
    {
        if (!$this->changed || $this->file === null) {
            return null;
        }

        $rejected = $this->rejected;
        \usort($rejected, static fn(array $a, array $b): int => [$a['function'], $a['rule']] <=> [$b['function'], $b['rule']]);
        $yaml = "# Changes declined in `opmin optimize --review`: opmin does not propose them again.\n"
            . "# Remove an entry to let opmin try the change again.\n"
            . Yaml::dump(['rejected' => $rejected] + $this->other, 2, 2);
        \file_put_contents((string) $this->file, $yaml);
        $this->changed = false;

        return $this->file;
    }

    /**
     * @return list<array{function: non-empty-string, rule: non-empty-string}>
     */
    public function all(): array
    {
        return $this->rejected;
    }
}
