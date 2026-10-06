<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Reads coverage in the PHPUnit XML format (`--coverage-xml=dir` of PHPUnit, Pest and Testo):
 * `index.xml` lists the source files, each file's XML has `<line nr><covered by="Test::id"/></line>`.
 *
 * @internal
 */
final class CoverageXmlReport
{
    public static function read(string $dir): ?CoverageMap
    {
        $index = JunitReport::load($dir . '/index.xml');
        if ($index === null) {
            return null;
        }

        $project = $index->getElementsByTagName('project')->item(0);
        $source = $project instanceof \DOMElement ? $project->getAttribute('source') : '';
        $lines = [];
        /** @var \DOMElement $file */
        foreach ($index->getElementsByTagName('file') as $file) {
            $report = JunitReport::load($dir . '/' . $file->getAttribute('href'));
            $node = $report?->getElementsByTagName('file')->item(0);
            if (!$node instanceof \DOMElement || $report === null) {
                continue;
            }

            $path = \rtrim($source . '/' . \trim($node->getAttribute('path'), '/'), '/') . '/' . $node->getAttribute('name');
            $real = \realpath($path);
            $path = $real === false ? $path : $real;
            /** @var \DOMElement $line */
            foreach ($report->getElementsByTagName('line') as $line) {
                $tests = [];
                /** @var \DOMElement $covered */
                foreach ($line->getElementsByTagName('covered') as $covered) {
                    $by = $covered->getAttribute('by');
                    $by === '' or $tests[] = $by;
                }

                $tests === [] or $lines[$path][(int) $line->getAttribute('nr')] = $tests;
            }
        }

        /** @var array<non-empty-string, array<int, list<non-empty-string>>> $lines */
        return new CoverageMap($lines);
    }
}
