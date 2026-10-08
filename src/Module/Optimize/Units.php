<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Opmin\Module\Opcode\Locate\CodeUnit;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\LocateException;
use Opmin\Module\Opcode\Locate\UnitKind;

/**
 * The function-like units of one version of a file, by key, with their source: what changed between
 * two versions, and how to take a unit back from the other version.
 *
 * A change is taken or rolled back per top-level unit (a function, a method, a property hook): a
 * closure or a method of an anonymous class belongs to the unit it is written in.
 *
 * @internal
 */
final readonly class Units
{
    /**
     * @param array<non-empty-string, CodeUnit> $units Every unit but main code.
     */
    private function __construct(
        public string $code,
        public array $units,
    ) {}

    /**
     * @param non-empty-string $relative Path of the file relative to the project root.
     */
    public static function of(string $code, string $relative): self
    {
        try {
            $located = (new FunctionLocator())->locate($code, $relative . '::<main>');
        } catch (LocateException) {
            return new self($code, []);
        }

        $units = [];
        foreach ($located as $unit) {
            $unit->kind === UnitKind::Main || $unit->node === null or $units[$unit->key] = $unit;
        }

        return new self($code, $units);
    }

    /**
     * The top-level unit a key belongs to: `App\Foo::bar::{closure:1}` → `App\Foo::bar`; null for code
     * outside every function (main code, its closures).
     *
     * @return non-empty-string|null
     */
    public function topLevel(string $key): ?string
    {
        $at = \strpos($key, '::{');
        $top = $at === false ? $key : \substr($key, 0, $at);

        return $top !== '' && isset($this->units[$top]) ? $top : null;
    }

    /**
     * Source of a unit: from its attributes to its closing brace (the docblock is outside).
     */
    public function source(string $key): ?string
    {
        $node = ($this->units[$key] ?? null)?->node;

        return $node === null ? null : \substr($this->code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
    }

    /**
     * Docblock of a unit, null without one.
     */
    public function docComment(string $key): ?string
    {
        return ($this->units[$key] ?? null)?->node?->getDocComment()?->getText();
    }

    /**
     * Keys of top-level units whose source differs between this version and `$other` (or that exist
     * in one of them only).
     *
     * @return list<non-empty-string>
     */
    public function changedTopLevel(self $other): array
    {
        $changed = [];
        foreach (\array_unique([...\array_keys($this->units), ...\array_keys($other->units)]) as $key) {
            $top = $this->topLevel($key) ?? $other->topLevel($key) ?? $key;
            if (isset($changed[$top]) || ($this->units[$key] ?? null)?->node === null && ($other->units[$key] ?? null)?->node === null) {
                continue;
            }

            $this->source($key) === $other->source($key) && $this->docComment($key) === $other->docComment($key) or $changed[$top] = $top;
        }

        return \array_values($changed);
    }

    /**
     * This version with the given top-level units taken from `$donor` (their source and docblock);
     * null when a unit is missing in one of the versions.
     *
     * @param list<non-empty-string> $keys
     */
    public function withUnitsFrom(self $donor, array $keys): ?string
    {
        $ranges = [];
        foreach ($keys as $key) {
            $mine = ($this->units[$key] ?? null)?->node;
            $theirs = ($donor->units[$key] ?? null)?->node;
            if ($mine === null || $theirs === null) {
                return null;
            }

            $ranges[] = [self::start($mine), $mine->getEndFilePos(), \substr($donor->code, self::start($theirs), $theirs->getEndFilePos() - self::start($theirs) + 1)];
        }

        \usort($ranges, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $code = $this->code;
        foreach ($ranges as [$from, $to, $text]) {
            $code = \substr($code, 0, $from) . $text . \substr($code, $to + 1);
        }

        return $code;
    }

    /**
     * This version with the source of one top-level unit replaced by `$source`: from its docblock when
     * `$source` starts with one, from its attributes otherwise (the docblock stays). An unindented
     * `$source` gets the indentation of the unit, unless it has a heredoc, whose content depends on it.
     *
     * @param non-empty-string $key
     * @return string|null Null when there is no such unit.
     */
    public function withSource(string $key, string $source): ?string
    {
        $node = ($this->units[$key] ?? null)?->node;
        if ($node === null) {
            return null;
        }

        $source = \trim(\str_replace("\r\n", "\n", $source));
        $from = \str_starts_with($source, '/**') ? self::start($node) : $node->getStartFilePos();
        $indent = $this->indentAt($from);
        $lines = \explode("\n", $source);
        # The closing brace of an unindented source is at the line start.
        $unindented = \count($lines) > 1 && \strspn((string) \end($lines), " \t") === 0;
        if ($indent !== '' && \trim($indent) === '' && $unindented && !\str_contains($source, '<<<')) {
            $source = \implode("\n", \array_map(
                static fn(string $l, int $i): string => $i === 0 || \trim($l) === '' ? $l : $indent . $l,
                $lines,
                \array_keys($lines),
            ));
        }

        return \substr($this->code, 0, $from) . $source . \substr($this->code, $node->getEndFilePos() + 1);
    }

    /**
     * Whitespace before a unit (its docblock) on its first line: what the source of the unit lacks
     * to be shown as it is in the file.
     */
    public function indent(string $key): string
    {
        $node = ($this->units[$key] ?? null)?->node;

        return $node === null ? '' : $this->indentAt(self::start($node));
    }

    /**
     * Keys of every unit inside a top-level unit, itself included.
     *
     * @return list<non-empty-string>
     */
    public function family(string $top): array
    {
        return \array_values(\array_filter(
            \array_keys($this->units),
            static fn(string $key): bool => $key === $top || \str_starts_with($key, $top . '::{'),
        ));
    }

    /**
     * Start of a unit with its docblock.
     */
    private static function start(\PhpParser\Node $node): int
    {
        $doc = $node->getDocComment();

        return $doc === null ? $node->getStartFilePos() : \min($doc->getStartFilePos(), $node->getStartFilePos());
    }

    private function indentAt(int $offset): string
    {
        $lineStart = \strrpos(\substr($this->code, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $indent = \substr($this->code, $lineStart, $offset - $lineStart);

        return \trim($indent) === '' ? $indent : '';
    }
}
