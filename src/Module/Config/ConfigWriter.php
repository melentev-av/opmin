<?php

declare(strict_types=1);

namespace Opmin\Module\Config;

use Opmin\Info;
use Symfony\Component\Yaml\Yaml;

/**
 * Renders `opmin.yaml` with every key, its default (or a given value) and a comment.
 *
 * This is what `opmin init` writes: the user sees the whole configuration at once and only edits
 * values, never has to look up key names.
 *
 * @internal
 */
final class ConfigWriter
{
    /** Lists longer than this (rendered inline) are written as blocks. */
    private const INLINE_LIMIT = 60;

    /**
     * @param array<non-empty-string, mixed> $values Values that replace defaults, by key path.
     */
    public static function render(array $values = []): string
    {
        $lines = [
            '# yaml-language-server: $schema=' . Info::SCHEMA_URL,
            '# opmin configuration: https://github.com/melentev-av/opmin',
            '# Every key can be overridden by the environment (OPMIN_<KEY>, e.g. OPMIN_VERIFICATION_SEED)',
            '# and on the command line (--set=verification.seed=7).',
        ];

        $opened = [];
        foreach (ConfigSchema::keys() as $path => $key) {
            $segments = \explode('.', $path);
            $leaf = \array_pop($segments);

            # Open the sections this key lives in, skipping the ones already open
            $common = 0;
            while (isset($segments[$common], $opened[$common]) && $segments[$common] === $opened[$common]) {
                $common++;
            }

            $common === 0 && ($segments !== [] || $opened !== []) and $lines[] = '';
            for ($depth = $common; $depth < \count($segments); $depth++) {
                $lines[] = self::indent($depth) . $segments[$depth] . ':';
            }
            $opened = $segments;

            $value = \array_key_exists($path, $values) ? $values[$path] : $key->default;
            \array_push($lines, ...self::renderKey($leaf, $value, $key, \count($segments)));
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private static function renderKey(string $name, mixed $value, KeyInfo $key, int $depth): array
    {
        $indent = self::indent($depth);
        $value instanceof \BackedEnum and $value = $value->value;
        $comment = '# ' . $key->key->description;

        if (!\is_array($value)) {
            return [\sprintf('%s%s: %s  %s', $indent, $name, Yaml::dump($value), $comment)];
        }

        if ($value === []) {
            return [\sprintf('%s%s: %s  %s', $indent, $name, $key->key->list ? '[]' : '{}', $comment)];
        }

        $inline = Yaml::dump($value, 0);
        if (\array_is_list($value) && \strlen($inline) <= self::INLINE_LIMIT) {
            return [\sprintf('%s%s: %s  %s', $indent, $name, $inline, $comment)];
        }

        $block = \rtrim(Yaml::dump($value, 2, 2));
        $lines = [$indent . $comment, $indent . $name . ':'];
        foreach (\explode("\n", $block) as $line) {
            $lines[] = self::indent($depth + 1) . $line;
        }

        return $lines;
    }

    private static function indent(int $depth): string
    {
        return \str_repeat('  ', $depth);
    }
}
