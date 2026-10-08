<?php

declare(strict_types=1);

namespace Opmin\Module\Optimize;

use Opmin\Module\Analysis\Restriction;
use Opmin\Module\Config\Schema;
use Opmin\Module\Config\Schema\NativeTypesPolicy;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/**
 * Whether a change of a function's signature or docblock is allowed (brief, «Изменение сигнатур и
 * phpdoc»).
 *
 * - Native types (parameters, return) change only under `signatures.native_types`: `none` never,
 *   `non_overridable` — functions, private and final methods, methods of final classes (public
 *   overridable ones with `signatures.public_api`), `all` — any. The differential tester then
 *   checks inputs outside the new types.
 * - Anything else in the signature — names (named arguments), defaults, by-reference, variadics,
 *   the number of parameters — breaks callers and is never changed.
 * - The docblock changes only with `signatures.phpdoc`.
 * - A function with {@see Restriction::Signature} (called dynamically, inspected by reflection,
 *   `func_get_args()`) keeps its signature as is; with {@see Restriction::Docblock} its docblock.
 *
 * @internal
 */
final readonly class SignatureGate
{
    private Standard $printer;

    public function __construct(
        private Schema\Signatures $config,
        private bool $allowPublic = false,
    ) {
        $this->printer = new Standard();
    }

    /**
     * Why the change is not allowed, or null.
     *
     * @param list<Restriction> $restrictions Of the original function.
     */
    public function reject(
        Node\FunctionLike $before,
        Node\FunctionLike $after,
        ?string $docBefore,
        ?string $docAfter,
        ?Stmt\ClassLike $class,
        array $restrictions,
    ): ?string {
        if ($docBefore !== $docAfter && (!$this->config->phpdoc || \in_array(Restriction::Docblock, $restrictions, true))) {
            return 'changes the docblock';
        }

        [$shapeBefore, $typesBefore] = $this->signature($before);
        [$shapeAfter, $typesAfter] = $this->signature($after);
        if ($shapeBefore !== $shapeAfter) {
            return 'changes the signature';
        }

        if ($typesBefore === $typesAfter) {
            return null;
        }

        if (\in_array(Restriction::Signature, $restrictions, true)) {
            return 'changes native types of a function with a fixed signature';
        }

        return match ($this->config->nativeTypes) {
            NativeTypesPolicy::None => 'changes native types (signatures.native_types: none)',
            NativeTypesPolicy::All => null,
            NativeTypesPolicy::NonOverridable => $this->overridable($after, $class) && !$this->config->publicApi && !$this->allowPublic
                ? 'changes native types of an overridable method (signatures.public_api)'
                : null,
        };
    }

    /**
     * @return array{string, string} [shape without types, types]
     */
    private function signature(Node\FunctionLike $function): array
    {
        $shape = [$function->returnsByRef() ? '&' : ''];
        $return = $function->getReturnType();
        $types = [$return === null ? '' : $this->printer->prettyPrint([$return])];
        foreach ($function->getParams() as $param) {
            $name = $param->var instanceof Node\Expr\Variable && \is_string($param->var->name) ? $param->var->name : '?';
            $shape[] = \implode(' ', [
                $param->byRef ? '&' : '',
                $param->variadic ? '...' : '',
                $name,
                $param->default === null ? '' : '= ' . $this->printer->prettyPrintExpr($param->default),
                (string) $param->flags,
            ]);
            $types[] = $param->type === null ? '' : $this->printer->prettyPrint([$param->type]);
        }

        return [\implode(',', $shape), \implode(',', $types)];
    }

    private function overridable(Node\FunctionLike $function, ?Stmt\ClassLike $class): bool
    {
        if (!$function instanceof Stmt\ClassMethod) {
            return false;
        }

        return !$function->isPrivate() && !$function->isFinal() && !($class instanceof Stmt\Class_ && $class->isFinal())
            && !$class instanceof Stmt\Enum_;
    }
}
