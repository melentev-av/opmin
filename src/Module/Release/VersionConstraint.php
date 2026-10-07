<?php

declare(strict_types=1);

namespace Opmin\Module\Release;

/**
 * The opmin version a project asks for (`.opmin-version`, `requires` in `opmin.yaml`): an exact version
 * (`1.2.3`) or a composer-like constraint — `^1.2`, `~1.2`, `1.2.*`, `>=1.2 <2`, alternatives with `||`.
 *
 * @internal
 */
final readonly class VersionConstraint
{
    /**
     * @param non-empty-string $constraint
     * @param list<list<array{'=='|'!='|'<'|'<='|'>'|'>=', non-empty-string}>> $alternatives Each is a list of
     *        `[operator, version]` that must all hold.
     */
    private function __construct(
        public string $constraint,
        private array $alternatives,
    ) {}

    /**
     * @throws \InvalidArgumentException On a constraint that cannot be read.
     */
    public static function parse(string $constraint): self
    {
        $constraint = \trim($constraint);
        $constraint === '' and throw new \InvalidArgumentException('The opmin version constraint is empty.');
        $alternatives = [];
        foreach (\preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            $all = [];
            foreach (\preg_split('/[\s,]+/', \trim($alternative)) ?: [] as $part) {
                $part === '' or \array_push($all, ...self::part($part, $constraint));
            }

            $all === [] or $alternatives[] = $all;
        }

        $alternatives === [] and throw new \InvalidArgumentException("Cannot read the opmin version constraint `{$constraint}`.");

        return new self($constraint, $alternatives);
    }

    public function allows(string $version): bool
    {
        $version = \ltrim($version, 'v');
        foreach ($this->alternatives as $all) {
            $ok = true;
            foreach ($all as [$operator, $bound]) {
                $ok = $ok && \version_compare($version, $bound, $operator);
            }

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * The version to pass to `self-update --to=` when the constraint names exactly one.
     *
     * @return non-empty-string|null
     */
    public function exact(): ?string
    {
        if (\count($this->alternatives) !== 1 || \count($this->alternatives[0]) !== 1) {
            return null;
        }

        [$operator, $version] = $this->alternatives[0][0];

        return $operator === '==' ? $version : null;
    }

    /**
     * @return list<array{'=='|'!='|'<'|'<='|'>'|'>=', non-empty-string}>
     */
    private static function part(string $part, string $constraint): array
    {
        if (\preg_match('/^(\^|~|>=|<=|>|<|==|=|!=)?v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?$/', $part, $m) !== 1) {
            throw new \InvalidArgumentException("Cannot read `{$part}` in the opmin version constraint `{$constraint}`.");
        }

        $operator = $m[1] ?? '';
        $major = (int) $m[2];
        $minor = ($m[3] ?? '') === '' || $m[3] === '*' ? null : (int) $m[3];
        $patch = ($m[4] ?? '') === '' || $m[4] === '*' ? null : (int) $m[4];
        $wildcard = ($m[3] ?? '') === '*' || ($m[4] ?? '') === '*';
        $wildcard && $operator !== '' and throw new \InvalidArgumentException("A wildcard cannot follow `{$operator}` in `{$constraint}`.");
        $full = \sprintf('%d.%d.%d', $major, $minor ?? 0, $patch ?? 0);
        $nextMajor = ($major + 1) . '.0.0';
        $nextMinor = $major . '.' . (($minor ?? 0) + 1) . '.0';

        return match ($operator) {
            # 1 and 1.* — any 1.x; 1.2 and 1.2.* — any 1.2.x; 1.2.3 — exactly.
            '' => match (true) {
                $minor === null => [['>=', $full], ['<', $nextMajor]],
                $patch === null => [['>=', $full], ['<', $nextMinor]],
                default => [['==', $full]],
            },
            '=', '==' => [['==', $full]],
            '!=' => [['!=', $full]],
            # Composer's caret: the leftmost non-zero part is fixed.
            '^' => [['>=', $full], ['<', match (true) {
                $major > 0 || $minor === null => $nextMajor,
                $minor > 0 || $patch === null => $nextMinor,
                default => "0.0." . ($patch + 1),
            }]],
            # ~1.2 — up to 2.0; ~1.2.3 — up to 1.3.
            '~' => [['>=', $full], ['<', $patch === null ? $nextMajor : $nextMinor]],
            '>=', '<=', '>', '<' => [[$operator, $full]],
            default => throw new \InvalidArgumentException("Unknown operator `{$operator}` in `{$constraint}`."),
        };
    }
}
