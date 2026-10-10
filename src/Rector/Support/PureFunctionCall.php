<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\ParametersAcceptorSelectorVariantsWrapper;
use Rector\Reflection\ReflectionResolver;

/**
 * Whether a function call runs no user code and changes nothing but its result.
 *
 * Only built-in functions of the always compiled-in core and `standard` extensions are known: a
 * function of an optional extension may be a userland polyfill. They take no callable, class name
 * or reference, and with arguments of their own native types emit no warning or deprecation (an
 * error handler is user code); invalid values only throw. Every argument must be a scalar by
 * native type, exactly of the parameter's type: an object may run `__toString()`, an array may
 * hold objects, a coerced value may emit a deprecation.
 *
 * @internal
 */
final readonly class PureFunctionCall
{
    /**
     * Known functions => the most arguments allowed, below the arity where an optional argument may
     * emit a warning (`trim($s, 'a..')` warns about the invalid range) or is taken by reference.
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
     * @param \Closure(Expr): ?Type $nativeType
     */
    public function __construct(
        private ReflectionResolver $reflectionResolver,
        private \Closure $nativeType,
    ) {}

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
        $args = $call->getArgs();
        if (\count($args) > (self::FUNCTIONS[$name] ?? -1)) {
            return false;
        }

        try {
            $reflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($call);
            if ($reflection === null) {
                return false;
            }

            $parameters = ParametersAcceptorSelectorVariantsWrapper::select($reflection, $call, $scope)->getParameters();
        } catch (\Throwable) {
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
            ) {
                return false;
            }
        }

        return true;
    }
}
