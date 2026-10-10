<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Reads coverage in the PHPUnit XML format (`--coverage-xml=dir` of PHPUnit, Pest and Testo):
 * `index.xml` lists the source files, each file's XML has `<line nr><covered by="Test::id"/></line>`
 * ({@see XmlElements}).
 *
 * @internal
 */
final class CoverageXmlReport
{
    public static function read(string $dir): ?CoverageMap
    {
        $index = XmlElements::document($dir . '/index.xml', 'phpunit');
        if ($index === null) {
            return null;
        }

        $source = XmlElements::find($index, 'project')[0]['attributes']['source'] ?? '';
        $lines = [];
        foreach (XmlElements::find($index, 'file') as ['attributes' => $file]) {
            $report = XmlElements::document($dir . '/' . ($file['href'] ?? ''), 'phpunit');
            $node = $report === null ? null : (XmlElements::find($report, 'file')[0] ?? null);
            if ($node === null) {
                continue;
            }

            $attributes = $node['attributes'];
            $path = \rtrim($source . '/' . \trim($attributes['path'] ?? '', '/'), '/') . '/' . ($attributes['name'] ?? '');
            $real = \realpath($path);
            $path = $real === false ? $path : $real;
            # `<line nr>` of the coverage; `<line no>` of the highlighted source is not.
            foreach (XmlElements::find($node['body'], 'line') as ['attributes' => $line, 'body' => $body]) {
                if (!isset($line['nr'])) {
                    continue;
                }

                $tests = [];
                foreach (XmlElements::find($body, 'covered') as ['attributes' => $covered]) {
                    ($covered['by'] ?? '') === '' or $tests[] = $covered['by'];
                }

                $tests === [] or $lines[$path][(int) $line['nr']] = $tests;
            }
        }

        /** @var array<non-empty-string, array<int, list<non-empty-string>>> $lines */
        return new CoverageMap($lines);
    }
}
