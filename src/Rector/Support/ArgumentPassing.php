<?php

declare(strict_types=1);

namespace Opmin\Rector\Support;

use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\ParametersAcceptorSelectorVariantsWrapper;
use Rector\Reflection\ReflectionResolver;

/**
 * Whether an argument of a call is passed by reference, from the callee's reflection.
 *
 * @internal
 */
final readonly class ArgumentPassing
{
    public function __construct(
        private ReflectionResolver $reflectionResolver,
    ) {}

    /**
     * @return ?bool null — the callee or its parameter is unknown.
     */
    public function byReference(CallLike $call, int $position): ?bool
    {
        /** @var mixed $scope */
        $scope = $call->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope) {
            return null;
        }

        try {
            $reflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($call);
            if ($reflection === null) {
                return null;
            }

            $parameters = ParametersAcceptorSelectorVariantsWrapper::select($reflection, $call, $scope)->getParameters();
        } catch (\Throwable) {
            return null;
        }

        $parameter = $parameters[$position] ?? null;
        if ($parameter === null) {
            $last = $parameters === [] ? null : $parameters[\count($parameters) - 1];
            $parameter = $last !== null && $last->isVariadic() ? $last : null;
        }

        return $parameter === null ? null : !$parameter->passedByReference()->no();
    }
}
