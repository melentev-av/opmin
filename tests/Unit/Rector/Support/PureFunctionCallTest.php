<?php

declare(strict_types=1);

namespace Opmin\Tests\Unit\Rector\Support;

use Opmin\Rector\Support\PureFunctionCall;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Which built-in functions PHPStan's metadata may make pure. The calls themselves (arguments,
 * arity, return types) are classified by the fixtures of `tests/Rector/PureFunctionCallProbeRector`.
 */
#[Test]
#[Covers(PureFunctionCall::class)]
final class PureFunctionCallTest
{
    /**
     * Every function without side effects by the metadata that {@see PureFunctionCall::candidate()}
     * accepts, checked by hand: deterministic, independent of the environment, and with only its
     * required arguments of their own scalar types, silent or throwing. A new name here (a PHPStan
     * update, a newer PHP) is reviewed and either added or put on the denylist of the class.
     */
    private const REVIEWED = [
        'abs', 'acos', 'acosh', 'addslashes', 'array_change_key_case', 'array_chunk', 'array_column',
        'array_combine', 'array_count_values', 'array_diff', 'array_diff_assoc', 'array_diff_key', 'array_fill',
        'array_fill_keys', 'array_flip', 'array_intersect', 'array_intersect_assoc', 'array_intersect_key',
        'array_is_list', 'array_key_exists', 'array_key_first', 'array_key_last', 'array_keys', 'array_merge',
        'array_merge_recursive', 'array_pad', 'array_product', 'array_replace', 'array_replace_recursive',
        'array_reverse', 'array_search', 'array_slice', 'array_sum', 'array_unique', 'array_values', 'asin', 'asinh',
        'atan', 'atan2', 'atanh', 'base64_decode', 'base64_encode', 'basename', 'bin2hex', 'boolval', 'ceil',
        'checkdate', 'chop', 'chunk_split', 'convert_uuencode', 'cos', 'cosh', 'count', 'count_chars', 'crc32',
        'decbin', 'dechex', 'decoct', 'deg2rad', 'dirname', 'doubleval', 'exp', 'explode', 'expm1', 'fdiv',
        'floatval', 'floor', 'fmod', 'get_debug_type', 'get_html_translation_table', 'get_resource_id', 'gettype',
        'hash', 'hash_copy', 'hash_equals', 'hash_hkdf', 'hash_hmac', 'hash_init', 'hash_pbkdf2', 'hebrev',
        'html_entity_decode', 'htmlentities', 'htmlspecialchars', 'htmlspecialchars_decode', 'http_build_query',
        'hypot', 'image_type_to_extension', 'image_type_to_mime_type', 'implode', 'in_array', 'intdiv', 'intval',
        'ip2long', 'is_array', 'is_bool', 'is_countable', 'is_double', 'is_finite', 'is_float', 'is_infinite',
        'is_int', 'is_integer', 'is_iterable', 'is_long', 'is_nan', 'is_null', 'is_numeric', 'is_object',
        'is_resource', 'is_scalar', 'is_string', 'join', 'key_exists', 'lcfirst', 'log', 'log10', 'log1p', 'long2ip',
        'ltrim', 'max', 'md5', 'metaphone', 'min', 'nl2br', 'number_format', 'parse_url', 'pi', 'preg_quote',
        'quoted_printable_decode', 'quoted_printable_encode', 'quotemeta', 'rad2deg', 'rawurldecode', 'rawurlencode',
        'round', 'rtrim', 'sha1', 'sin', 'sinh', 'sizeof', 'soundex', 'sprintf', 'sqrt', 'str_contains',
        'str_ends_with', 'str_pad', 'str_repeat', 'str_rot13', 'str_split', 'str_starts_with', 'str_word_count',
        'strcasecmp', 'strchr', 'strcmp', 'strcoll', 'strcspn', 'strip_tags', 'stripcslashes', 'stripos',
        'stripslashes', 'stristr', 'strlen', 'strnatcasecmp', 'strnatcmp', 'strncasecmp', 'strncmp', 'strpbrk',
        'strpos', 'strrchr', 'strrev', 'strripos', 'strrpos', 'strspn', 'strstr', 'strtolower', 'strtoupper',
        'strtr', 'strval', 'substr', 'substr_compare', 'substr_count', 'substr_replace', 'tan', 'tanh',
        'timezone_abbreviations_list', 'timezone_name_get', 'trim', 'ucfirst', 'ucwords', 'urldecode', 'urlencode',
        'version_compare', 'vsprintf', 'wordwrap',
    ];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'core' => ['strlen', true];
        yield 'standard' => ['htmlspecialchars', true];
        yield 'hash, always compiled in' => ['hash', true];
        yield 'pcre, always compiled in' => ['preg_quote', true];
        yield 'time' => ['time', false];
        yield 'randomness' => ['mt_rand', false];
        yield 'file system' => ['file_exists', false];
        yield 'setting' => ['ini_get', false];
        yield 'autoloading' => ['class_exists', false];
        yield 'code declared at run time' => ['function_exists', false];
        yield 'calling frame' => ['func_get_args', false];
        yield 'deprecation on a valid value' => ['chr', false];
        yield 'warning on a valid value' => ['convert_uudecode', false];
        yield 'newer than PHP 8.1' => ['str_increment', false];
        yield 'optional extension' => ['ctype_digit', false];
        yield 'unknown' => ['opmin_no_such_function', false];
        yield 'namespaced' => ['app\\strlen', false];
    }

    #[DataProvider('names')]
    public function knowsCandidates(string $name, bool $expected): void
    {
        $candidate = PureFunctionCall::candidate($name);

        Assert::same($candidate, $expected);
    }

    public function everyCandidateOfTheMetadataIsReviewed(): void
    {
        /** @var array<string, array{hasSideEffects?: bool}> $metadata */
        $metadata = require 'phar://' . \dirname(__DIR__, 4) . '/vendor/phpstan/phpstan/phpstan.phar/resources/functionMetadata.php';
        $unreviewed = [];
        foreach ($metadata as $name => $meta) {
            $name = \strtolower($name);
            if (!\str_contains($name, '::') && ($meta['hasSideEffects'] ?? true) === false
                && PureFunctionCall::candidate($name) && !\in_array($name, self::REVIEWED, true)
            ) {
                $unreviewed[] = $name;
            }
        }

        Assert::same($unreviewed, [], 'Review these functions: add them to REVIEWED or deny them in PureFunctionCall: ' . \implode(', ', $unreviewed));
    }
}
