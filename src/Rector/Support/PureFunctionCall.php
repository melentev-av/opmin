<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ResourceType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\ParametersAcceptorSelectorVariantsWrapper;
use Rector\Reflection\ReflectionResolver;

/**
 * Whether a function call runs no user code, changes nothing but its result and gives the same
 * result for the same arguments while nothing impure runs in between (level L0 of the effect model).
 *
 * A function is known in one of two ways:
 * - reviewed by hand ({@see self::FUNCTIONS}) with the most arguments allowed;
 * - built in, of an extension always compiled in ({@see self::EXTENSIONS}: a function of an optional
 *   extension may be a userland polyfill), without side effects by PHPStan's function metadata
 *   (`#[Pure]` of phpstorm-stubs; `#[Pure(true)]`, which reads global state, counts as a side
 *   effect there), not deprecated, not {@see self::DENIED} and returning neither an object nor a
 *   resource. Only its required parameters may be passed: an optional one is where a function
 *   tends to warn about a valid-typed value (`trim($s, 'a..')`, `htmlspecialchars($s, 0, 'foo')`).
 *
 * The call passes no callable, class name or reference: every argument must be a scalar by native
 * type, exactly of the parameter's native type — an object may run `__toString()`, an array may hold
 * objects, a coerced value may emit a deprecation, and a warning or deprecation runs the error
 * handler (user code). Invalid values may only throw.
 *
 * A locale-aware function (`strtolower()` before PHP 8.2, `strcoll()`, `basename()`) counts as
 * pure: the locale changes only by `setlocale()`, which is not pure, so two calls with nothing
 * impure in between agree.
 *
 * @internal
 */
final readonly class PureFunctionCall
{
    /**
     * Extensions compiled into every PHP 8.1+ (lower-cased names).
     */
    private const EXTENSIONS = ['core', 'standard', 'date', 'hash', 'json', 'pcre', 'spl'];

    /**
     * Reviewed functions => the most arguments allowed, below the arity where an optional argument may
     * emit a warning (`trim($s, 'a..')` warns about the invalid range) or is taken by reference.
     * Trusted over the metadata: `str_replace()` is impure there only for its by-reference `$count`.
     */
    private const FUNCTIONS = [
        'abs' => 1,
        'basename' => 2,
        'bin2hex' => 1,
        'boolval' => 1,
        'ceil' => 1,
        'crc32' => 1,
        'dechex' => 1,
        'dirname' => 2,
        'explode' => 3,
        'floatval' => 1,
        'floor' => 1,
        'fmod' => 2,
        'intdiv' => 2,
        'is_bool' => 1,
        'is_float' => 1,
        'is_int' => 1,
        'is_null' => 1,
        'is_numeric' => 1,
        'is_string' => 1,
        'lcfirst' => 1,
        'ltrim' => 1,
        'max' => \PHP_INT_MAX,
        'md5' => 2,
        'min' => \PHP_INT_MAX,
        'number_format' => 4,
        'round' => 3,
        'rtrim' => 1,
        'sha1' => 2,
        'str_contains' => 2,
        'str_ends_with' => 2,
        'str_pad' => 4,
        'str_repeat' => 2,
        'str_replace' => 3,
        'str_starts_with' => 2,
        'strcasecmp' => 2,
        'strcmp' => 2,
        'stripos' => 3,
        'strlen' => 1,
        'strpos' => 3,
        'strrev' => 1,
        'strripos' => 3,
        'strrpos' => 3,
        'strtolower' => 1,
        'strtoupper' => 1,
        'strval' => 1,
        'substr' => 3,
        'substr_count' => 4,
        'trim' => 1,
        'ucfirst' => 1,
        'ucwords' => 2,
    ];

    /**
     * Never pure, whatever the metadata says. Most of these are impure there anyway; they are listed
     * so that a change of the metadata does not make them pure.
     */
    private const DENIED = [
        # A different result on every call: time, randomness, identity of objects.
        'time', 'microtime', 'hrtime', 'gettimeofday', 'date', 'gmdate', 'idate', 'localtime', 'getdate',
        'mktime', 'strtotime', 'rand', 'mt_rand', 'random_int', 'random_bytes', 'lcg_value', 'uniqid',
        'array_rand', 'shuffle', 'str_shuffle', 'getrandmax', 'mt_getrandmax', 'spl_object_id',
        'spl_object_hash',
        # The file system, the network, the process: changes outside the function.
        'file_exists', 'is_file', 'is_dir', 'is_link', 'is_readable', 'is_writable', 'is_writeable',
        'is_executable', 'fileatime', 'filectime', 'filegroup', 'fileinode', 'filemtime', 'fileowner',
        'fileperms', 'filesize', 'filetype', 'stat', 'lstat', 'realpath', 'disk_free_space',
        'disk_total_space', 'getcwd', 'ftell', 'stream_is_local', 'stream_isatty', 'hash_file',
        'hash_hmac_file', 'checkdnsrr', 'gethostbyaddr', 'gethostbyname', 'gethostbynamel', 'gethostname',
        'getprotobyname', 'getprotobynumber', 'getservbyname', 'getservbyport', 'net_get_interfaces',
        'getmypid', 'getmygid', 'getmyuid', 'getmyinode', 'getlastmod', 'sys_getloadavg', 'memory_get_usage',
        'memory_get_peak_usage', 'getenv', 'sys_get_temp_dir',
        # The runtime: settings, loaded code, buffers, the last error. Some never change in a process,
        # but nothing is gained by trusting them.
        'ini_get', 'get_cfg_var', 'get_include_path', 'error_reporting', 'localeconv', 'nl_langinfo',
        'extension_loaded', 'get_extension_funcs', 'get_loaded_extensions', 'phpversion', 'zend_version',
        'php_sapi_name', 'php_uname', 'php_ini_loaded_file', 'php_ini_scanned_files', 'hash_algos',
        'hash_hmac_algos', 'spl_classes', 'date_default_timezone_get', 'timezone_version_get',
        'headers_list', 'headers_sent', 'ob_get_level', 'error_get_last', 'json_last_error',
        'json_last_error_msg', 'preg_last_error', 'preg_last_error_msg',
        # The calling frame: its arguments, variables and class.
        'func_get_arg', 'func_get_args', 'func_num_args', 'compact', 'get_defined_vars', 'get_called_class',
        'get_class', 'get_parent_class', 'current', 'key', 'pos',
        # A class or function name: autoloading, or code declared at run time.
        'class_exists', 'interface_exists', 'trait_exists', 'enum_exists', 'function_exists', 'is_callable',
        'defined', 'constant', 'method_exists', 'property_exists', 'is_a', 'is_subclass_of',
        'get_class_methods', 'get_class_vars', 'class_implements', 'class_parents', 'class_uses',
        'iterator_count',
        # A warning or deprecation on a valid-typed value of a required parameter.
        'addcslashes', 'base_convert', 'bindec', 'hexdec', 'octdec', 'chr', 'ord', 'pow', 'range',
        'convert_uudecode', 'pack', 'unpack', 'str_getcsv', 'utf8_encode', 'utf8_decode', 'parse_ini_string',
        'iptcparse', 'inet_ntop', 'inet_pton', 'crypt', 'escapeshellarg', 'escapeshellcmd', 'preg_grep',
        'preg_split', 'json_validate',
        # Newer than PHP 8.1, the oldest target: there a polyfill (user code) may stand in for them.
        'str_increment', 'str_decrement', 'array_first', 'array_last',
        # Only in a build with `--with-mhash`, deprecated since PHP 8.1.
        'mhash', 'mhash_count', 'mhash_get_block_size', 'mhash_get_hash_name', 'mhash_keygen_s2k',
    ];

    /**
     * @param \Closure(Expr): ?Type $nativeType
     */
    public function __construct(
        private ReflectionResolver $reflectionResolver,
        private \Closure $nativeType,
    ) {}

    /**
     * A function the metadata may make pure: built in, of an always compiled-in extension of the PHP
     * running Rector, not denied. Lower-cased name.
     */
    public static function candidate(string $name): bool
    {
        if (\in_array($name, self::DENIED, true) || !\function_exists($name)) {
            return false;
        }

        $function = new \ReflectionFunction($name);
        $extension = $function->getExtensionName();

        return $function->isInternal() && \is_string($extension)
            && \in_array(\strtolower($extension), self::EXTENSIONS, true);
    }

    public function pure(FuncCall $call): bool
    {
        /** @var mixed $scope */
        $scope = $call->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope || !$call->name instanceof Name || $call->isFirstClassCallable()) {
            return false;
        }

        # An unqualified name in a namespace may resolve to a namespaced function at run time.
        $name = $call->name->isFullyQualified()
            ? $call->name->toLowerString()
            : \strtolower((string) $call->name->getAttribute('resolvedName'));
        $reviewed = isset(self::FUNCTIONS[$name]);
        if (!$reviewed && !self::candidate($name)) {
            return false;
        }

        $args = $call->getArgs();
        try {
            $reflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($call);
            if (!$reflection instanceof FunctionReflection || !$reflection->isBuiltin()
                || \strtolower($reflection->getName()) !== $name || $reflection->isDeprecated()->yes()
                || !$reviewed && !$reflection->hasSideEffects()->no()
            ) {
                return false;
            }

            $variant = ParametersAcceptorSelectorVariantsWrapper::select($reflection, $call, $scope);
            $parameters = $variant->getParameters();
        } catch (\Throwable) {
            return false;
        }

        $return = $variant->getReturnType();
        if (!$reviewed && (!$return->isObject()->no() || !(new ResourceType())->isSuperTypeOf($return)->no())) {
            return false;
        }

        $maxArgs = self::FUNCTIONS[$name] ?? \count(\array_filter(
            $parameters,
            static fn(ParameterReflection $parameter): bool => !$parameter->isOptional(),
        ));
        if (\count($args) > $maxArgs) {
            return false;
        }

        foreach (\array_values($args) as $position => $arg) {
            $parameter = $parameters[$position] ?? null;
            if ($parameter === null && $parameters !== [] && $parameters[\count($parameters) - 1]->isVariadic()) {
                $parameter = $parameters[\count($parameters) - 1];
            }

            $type = ($this->nativeType)($arg->value);
            if ($arg->unpack || $arg->name !== null || !$parameter instanceof ExtendedParameterReflection
                || !$parameter->passedByReference()->no() || $type === null || !$type->isScalar()->yes()
                || !$parameter->getNativeType()->isSuperTypeOf($type)->yes()
                || $parameter->getType()->isCallable()->yes() || $parameter->getType()->isClassString()->yes()
            ) {
                return false;
            }
        }

        return true;
    }
}
