<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Reads a JUnit XML report (PHPUnit, Pest, Testo `--log-junit`).
 *
 * @internal
 */
final class JunitReport
{
    /**
     * @return array{tests: non-negative-int, failed: list<non-empty-string>, seconds: float}|null Null when the file is missing or not JUnit.
     */
    public static function read(string $path): ?array
    {
        $document = self::load($path);
        if ($document === null) {
            return null;
        }

        $tests = 0;
        $failed = [];
        $seconds = 0.0;
        /** @var \DOMElement $case */
        foreach ($document->getElementsByTagName('testcase') as $case) {
            ++$tests;
            $seconds += (float) $case->getAttribute('time');
            if ($case->getElementsByTagName('failure')->length > 0 || $case->getElementsByTagName('error')->length > 0) {
                $class = $case->getAttribute('class');
                $class === '' and $class = \str_replace('.', '\\', $case->getAttribute('classname'));
                $id = ($class === '' ? '' : $class . '::') . $case->getAttribute('name');
                $id === '' or $failed[] = $id;
            }
        }

        return ['tests' => $tests, 'failed' => $failed, 'seconds' => $seconds];
    }

    public static function load(string $path): ?\DOMDocument
    {
        $xml = @\file_get_contents($path);
        if ($xml === false || $xml === '' || \trim($xml) === '') {
            return null;
        }

        $document = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, \LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }

        return $loaded ? $document : null;
    }
}
