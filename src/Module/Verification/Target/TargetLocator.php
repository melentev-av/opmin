<?php

declare(strict_types=1);

namespace Opmin\Module\Verification\Target;

use Opmin\Module\Opcode\Locate\CodeUnit;
use Opmin\Module\Opcode\Locate\FunctionLocator;
use Opmin\Module\Opcode\Locate\LocateException;
use Opmin\Module\Opcode\Locate\UnitKind;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Finds a function by its key in one version of a file and describes how the harness calls it.
 *
 * Closures are called through a generated wrapper function in the closure's namespace, with the
 * file's `use` imports and `strict_types`, that returns the closure; its parameters are the `use`
 * variables (the free variables of an arrow function). The wrapper keeps the closure on its lines.
 * A trait method is called on a generated class that uses the trait.
 *
 * @internal
 */
final class TargetLocator
{
    public function __construct(
        private readonly FunctionLocator $locator = new FunctionLocator(),
    ) {}

    /**
     * @param non-empty-string $key
     * @param non-empty-string $file Path relative to the project root (keys of main code contain it).
     * @throws UnsupportedTarget
     */
    public function locate(string $code, string $key, string $file): Target
    {
        try {
            $units = $this->locator->locate($code, $file . '::<main>');
        } catch (LocateException $e) {
            throw new UnsupportedTarget($e->getMessage(), previous: $e);
        }

        $unit = null;
        foreach ($units as $candidate) {
            $candidate->key === $key and $unit = $candidate;
        }

        $unit === null and throw new UnsupportedTarget("`{$key}` is not in the file.");
        $unit->kind === UnitKind::Main and throw new UnsupportedTarget('Main code of a file is not verified.');
        ($unit->abstract || $unit->node === null) and throw new UnsupportedTarget("`{$key}` has no body.");
        $node = $unit->node;
        $stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $namespace = self::namespaceAt($stmts, $node->getStartLine());
        $class = $unit->class;
        $className = $class?->namespacedName?->toString();

        return match ($unit->kind) {
            UnitKind::Function => new Target($key, $unit->kind, ['kind' => 'function', 'name' => $unit->dumpName], $namespace, $node, null, null, $unit->flags),
            UnitKind::Method => $this->method($unit, $node, $class, $className, $namespace, $stmts, $code),
            UnitKind::Hook => $this->hook($unit, $node, $className, $namespace),
            UnitKind::Closure => $this->closure($unit, $node, $class, $className, $namespace, $stmts, $code),
            default => throw new UnsupportedTarget("`{$key}` cannot be called."),
        };
    }

    /**
     * `<?php`, `declare(strict_types=1)` of the file, the namespace and its imports — on as few lines
     * as possible, then empty lines up to the given line.
     *
     * @param array<Node> $stmts
     */
    private static function header(array $stmts, string $code, string $namespace, int $line): string
    {
        $parts = ['<?php'];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Declare_) {
                $parts[] = \substr($code, $stmt->getStartFilePos(), $stmt->getEndFilePos() - $stmt->getStartFilePos() + 1);
            }
        }

        $parts[] = $namespace === '' ? 'namespace;' : "namespace {$namespace};";
        $scope = $stmts;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_ && (string) $stmt->name === $namespace) {
                $scope = $stmt->stmts;
            }
        }

        foreach ($scope as $stmt) {
            if ($stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse) {
                $parts[] = \substr($code, $stmt->getStartFilePos(), $stmt->getEndFilePos() - $stmt->getStartFilePos() + 1);
            }
        }

        # `namespace;` is not valid: global code needs no namespace statement.
        $header = \str_replace(' namespace;', '', \implode(' ', $parts));

        return $header . \str_repeat("\n", \max(1, $line - 1));
    }

    /**
     * @param array<Node> $stmts
     */
    private static function namespaceAt(array $stmts, int $line): string
    {
        # php-parser puts the statements after `namespace X;` into the namespace node, like a block.
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_ && $stmt->getStartLine() <= $line && $stmt->getEndLine() >= $line) {
                return (string) $stmt->name;
            }
        }

        return '';
    }

    private static function enclosingMethod(?Stmt\ClassLike $class, Node $node): ?Stmt\ClassMethod
    {
        if ($class === null) {
            return null;
        }

        foreach ($class->getMethods() as $method) {
            if ($method->getStartFilePos() <= $node->getStartFilePos() && $method->getEndFilePos() >= $node->getEndFilePos()) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Variables an arrow function takes from the enclosing scope.
     *
     * @return list<string>
     */
    private static function freeVariables(Expr\ArrowFunction $node): array
    {
        $params = \array_map(static fn(Node\Param $p): string => $p->var instanceof Expr\Variable && \is_string($p->var->name) ? $p->var->name : '', $node->params);
        $names = [];
        /** @var list<Expr\Variable> $variables */
        $variables = (new NodeFinder())->findInstanceOf([$node->expr], Expr\Variable::class);
        foreach ($variables as $variable) {
            $name = $variable->name;
            \is_string($name) && $name !== 'this' && !\in_array($name, $params, true) && !self::isSuperglobal($name) and $names[$name] = true;
        }

        return \array_map(strval(...), \array_keys($names));
    }

    private static function isSuperglobal(string $name): bool
    {
        return \in_array($name, ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true);
    }

    /**
     * @param array<Node> $stmts
     */
    private function method(CodeUnit $unit, Node\FunctionLike $node, ?Stmt\ClassLike $class, ?string $className, string $namespace, array $stmts, string $code): Target
    {
        \assert($node instanceof Stmt\ClassMethod);
        ($className === null || $class === null) and throw new UnsupportedTarget('Methods of anonymous classes are not verified.');
        $name = $node->name->toString();
        $receiver = $node->isStatic() || \strtolower($name) === '__construct' ? null : $className;
        if (!$class instanceof Stmt\Trait_) {
            return new Target($unit->key, $unit->kind, ['kind' => 'method', 'class' => $className, 'name' => $name], $namespace, $node, $receiver, null, $unit->flags);
        }

        # A trait is called through a class that uses it.
        $user = 'OpminTraitUser' . \substr(\hash('sha256', $unit->key), 0, 12);
        $wrapper = self::header($stmts, $code, $namespace, 1) . "final class {$user} { use \\{$className}; }\n";
        $userClass = \ltrim("{$namespace}\\{$user}", '\\');

        return new Target(
            $unit->key,
            $unit->kind,
            ['kind' => 'method', 'class' => $userClass, 'name' => $name],
            $namespace,
            $node,
            $receiver === null ? null : $userClass,
            $wrapper,
            $unit->flags,
        );
    }

    private function hook(CodeUnit $unit, Node\FunctionLike $node, ?string $className, string $namespace): Target
    {
        $className === null and throw new UnsupportedTarget('Hooks of anonymous classes are not verified.');
        \preg_match('/::\$([^:]+)::(get|set)$/', $unit->key, $m) === 1 or throw new UnsupportedTarget("Unexpected hook key `{$unit->key}`.");

        return new Target(
            $unit->key,
            $unit->kind,
            ['kind' => 'hook', 'class' => $className, 'property' => $m[1], 'hook' => $m[2]],
            $namespace,
            $node,
            $className,
            null,
            $unit->flags,
        );
    }

    /**
     * @param array<Node> $stmts
     */
    private function closure(CodeUnit $unit, Node\FunctionLike $node, ?Stmt\ClassLike $class, ?string $className, string $namespace, array $stmts, string $code): Target
    {
        \assert($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction);
        $class !== null && $className === null and throw new UnsupportedTarget('Closures in anonymous classes are not verified.');
        $params = $node instanceof Expr\Closure
            ? \array_map(static fn(Node\ClosureUse $use): string => \is_string($use->var->name) ? $use->var->name : '', $node->uses)
            : self::freeVariables($node);
        $name = 'opmin_closure_' . \substr(\hash('sha256', $unit->key), 0, 12);
        $source = \substr($code, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
        $wrapper = self::header($stmts, $code, $namespace, $node->getStartLine())
            . "function {$name}(" . \implode(', ', \array_map(static fn(string $p): string => '$' . $p, $params)) . ') { return '
            . $source . "; }\n";

        # `$this` exists in a non-static closure defined in a non-static method of a class.
        $method = self::enclosingMethod($class, $node);
        $receiver = $className !== null && !$node->static && $method !== null && !$method->isStatic() ? $className : null;
        $call = ['kind' => 'closure', 'wrapper' => \ltrim("{$namespace}\\{$name}", '\\')];
        $className === null or $call['scope'] = $className;

        return new Target($unit->key, $unit->kind, $call, $namespace, $node, $receiver, $wrapper, $unit->flags);
    }
}
