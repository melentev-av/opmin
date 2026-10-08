<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Locate;

use Opmin\Module\Opcode\Dump\DumpBlock;
use Opmin\Module\Opcode\Dump\Phase;
use Opmin\Module\Analysis\Flag;
use Opmin\Module\Opcode\FunctionCount;

/**
 * Pairs the blocks of an OPcache dump with the units found by {@see FunctionLocator}.
 *
 * Both lists are in dump order, so they are walked side by side, and every pair is checked: the
 * kind (closure or named), the name of a named unit and the last line. Any disagreement is an
 * error — never a guess: a count attributed to the wrong function is worse than no count. The only
 * blocks without a unit are exact copies of a closure (see {@see self::isCopy()}).
 *
 * @internal
 */
final class DumpMatcher
{
    /**
     * @param list<CodeUnit> $units From {@see FunctionLocator::locate()}.
     * @param list<DumpBlock> $blocks Both phases of one file, from the dump parser.
     * @param non-empty-string $file Path relative to the project root, for the result.
     * @return list<FunctionCount> In dump order, without abstract methods.
     * @throws MatchException
     */
    public function match(array $units, array $blocks, string $file): array
    {
        $raw = $this->pair($units, \array_values(\array_filter($blocks, static fn(DumpBlock $b): bool => $b->phase === Phase::Raw)));
        $opt = $this->pair($units, \array_values(\array_filter($blocks, static fn(DumpBlock $b): bool => $b->phase === Phase::Opt)));

        $result = [];
        foreach ($opt as $key => [$unit, $block]) {
            $before = $raw[$key][1] ?? throw new MatchException("`{$key}` is missing in the dump before the optimizer.");
            $result[] = new FunctionCount(
                key: $unit->key,
                kind: $unit->kind,
                file: $file,
                line: $unit->kind === UnitKind::Main ? 1 : $block->lineStart,
                opsRaw: $before->ops,
                opsOpt: $block->ops,
                args: $block->args,
                vars: $block->vars,
                tmps: $block->tmps,
                opcodes: $block->opcodes,
                optimizable: $unit->kind !== UnitKind::Main,
                flags: Flag::values($unit->flags),
            );
        }

        return $result;
    }

    /**
     * The blocks of one phase by unit key, without abstract units.
     *
     * @param list<CodeUnit> $units From {@see FunctionLocator::locate()}.
     * @param list<DumpBlock> $blocks Both phases of one file, from the dump parser.
     * @return array<non-empty-string, DumpBlock>
     * @throws MatchException
     */
    public function blocks(array $units, array $blocks, Phase $phase): array
    {
        $pairs = $this->pair($units, \array_values(\array_filter($blocks, static fn(DumpBlock $b): bool => $b->phase === $phase)));

        return \array_map(static fn(array $pair): DumpBlock => $pair[1], $pairs);
    }

    /**
     * PHP 8.4+ compiles an unqualified call of a frameless function (`trim`, `str_replace`…) in a
     * namespace twice — the frameless call and the fallback to a namespaced function — so a closure
     * among its arguments is dumped once more, opcode for opcode. Such a copy is the same function of
     * the source: it is not counted again.
     *
     * @param list<DumpBlock> $paired Closure blocks already attributed.
     */
    private static function isCopy(DumpBlock $block, array $paired): bool
    {
        if (!$block->isClosure()) {
            return false;
        }

        foreach ($paired as $original) {
            if ($original->name === $block->name && $original->lineStart === $block->lineStart
                && $original->lineEnd === $block->lineEnd && $original->listing === $block->listing
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<CodeUnit> $units
     * @param list<DumpBlock> $blocks One phase.
     * @return array<non-empty-string, array{CodeUnit, DumpBlock}> By unit key, without abstract units.
     */
    private function pair(array $units, array $blocks): array
    {
        $pairs = [];
        $paired = [];
        $i = 0;
        foreach ($units as $unit) {
            $block = $blocks[$i] ?? null;
            # A copy is skipped only when it is not the unit expected here (two equal closures on one line).
            while ($block !== null && !$this->matches($unit, $block) && self::isCopy($block, $paired)) {
                $block = $blocks[++$i] ?? null;
            }

            if ($unit->abstract) {
                # PHP 8.1 dumps abstract methods, newer versions do not.
                $block !== null && $this->matches($unit, $block) and ++$i;
                continue;
            }

            $block === null and throw new MatchException(\sprintf(
                'The dump ended, but `%s` (line %d) was expected next.',
                $unit->key,
                $unit->line,
            ));
            $this->matches($unit, $block) or throw new MatchException(\sprintf(
                'Dump block `%s` (lines %d-%d) does not match `%s` (%s, lines %d-%d) expected at this position.',
                $block->name,
                $block->lineStart,
                $block->lineEnd,
                $unit->key,
                $unit->kind->value,
                $unit->line,
                $unit->dumpEndLine ?? $unit->endLine,
            ));
            $pairs[$unit->key] = [$unit, $block];
            $block->isClosure() and $paired[] = $block;
            ++$i;
        }

        while (isset($blocks[$i]) && self::isCopy($blocks[$i], $paired)) {
            ++$i;
        }

        isset($blocks[$i]) and throw new MatchException(\sprintf(
            'Dump block `%s` (lines %d-%d) has no matching function in the source.',
            $blocks[$i]->name,
            $blocks[$i]->lineStart,
            $blocks[$i]->lineEnd,
        ));

        return $pairs;
    }

    private function matches(CodeUnit $unit, DumpBlock $block): bool
    {
        if ($unit->kind === UnitKind::Main) {
            return $block->isMain();
        }

        if ($block->lineEnd !== ($unit->dumpEndLine ?? $unit->endLine)) {
            return false;
        }

        return match (true) {
            $unit->kind === UnitKind::Closure => $block->isClosure(),
            $unit->isAnonymousClassMethod() => \str_contains($block->name, '@anonymous')
                && \str_ends_with(\strtolower($block->name), \strtolower(\substr((string) $unit->dumpName, \strlen('@anonymous')))),
            # PHP names are case-insensitive; the dump keeps the declared case of the namespace.
            default => \strtolower($block->name) === \strtolower((string) $unit->dumpName),
        };
    }
}
