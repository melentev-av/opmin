<?php

declare(strict_types=1);

namespace Opmin\Module\Tests;

/**
 * Elements of the XML reports of test runners (JUnit, PHPUnit coverage), read without ext-dom: the
 * static opmin binary has no libxml. The reports are written by the runners: well-formed, attribute values
 * escaped, and no element nested in an element of the same name.
 *
 * @internal
 */
final class XmlElements
{
    /**
     * The document, or null when it is missing, empty or does not end with `</$root>` (a runner killed
     * while writing it).
     */
    public static function document(string $path, string $root): ?string
    {
        $xml = @\file_get_contents($path);
        if ($xml === false || !\str_ends_with(\rtrim($xml), "</{$root}>")) {
            return null;
        }

        return $xml;
    }

    /**
     * `<$tag …/>` and `<$tag …>…</$tag>` in document order.
     *
     * @param non-empty-string $tag
     * @return list<array{attributes: array<string, string>, body: string}>
     */
    public static function find(string $xml, string $tag): array
    {
        $name = \preg_quote($tag, '/');
        \preg_match_all(
            '/<' . $name . '((?:\s+[\w:.-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/',
            $xml,
            $matches,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        );
        $elements = [];
        foreach ($matches as $match) {
            $body = '';
            if ($match[2][0] === '') {
                $start = $match[0][1] + \strlen($match[0][0]);
                $end = \strpos($xml, "</{$tag}>", $start);
                $body = $end === false ? \substr($xml, $start) : \substr($xml, $start, $end - $start);
            }

            $elements[] = ['attributes' => self::attributes($match[1][0]), 'body' => $body];
        }

        return $elements;
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(string $source): array
    {
        \preg_match_all('/([\w:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $source, $matches, \PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $value = ($match[3] ?? '') !== '' ? $match[3] : $match[2];
            $attributes[$match[1]] = \html_entity_decode($value, \ENT_QUOTES | \ENT_XML1, 'UTF-8');
        }

        return $attributes;
    }
}
