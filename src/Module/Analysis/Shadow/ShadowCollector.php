<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis\Shadow;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Collects the {@see FileShadows} of a file: namespaced (and global) function and constant
 * declarations, and runtime mocks of global functions by the known libraries:
 *
 * - php-mock: `getFunctionMock(ns, name)`, `defineFunctionMock(ns, name)`, `PHPMockery::mock(ns, name)`,
 *   `new Mock(ns, name, …)`, `(new MockBuilder())->setNamespace(ns)->setName(name)`;
 * - symfony/phpunit-bridge: `ClockMock::register(X::class)`, `DnsMock::register(X::class)` and the
 *   `time-sensitive`/`dns-sensitive` groups of a test class (the listener registers the test class);
 * - `eval()` of code that declares functions.
 *
 * In `vendor/` only declarations count: mocking libraries call their own API and `eval()` their
 * generated code with variables, and the calls that matter are in the project's tests.
 *
 * An argument that is not a literal (or `__NAMESPACE__`, `X::class`, `__CLASS__`) is unknown and
 * covers every namespace or every function: shadowing is over-approximated on purpose.
 *
 * @internal
 */
final class ShadowCollector extends NodeVisitorAbstract
{
    /** Functions `ClockMock::register()` defines in a namespace (a superset across versions). */
    public const CLOCK_FUNCTIONS = [
        'time', 'microtime', 'sleep', 'usleep', 'date', 'gmdate', 'hrtime', 'strtotime', 'mktime', 'gmmktime',
        'idate', 'getdate', 'localtime', 'date_create', 'date_create_immutable',
    ];

    /** Functions `DnsMock::register()` defines in a namespace. */
    public const DNS_FUNCTIONS = [
        'checkdnsrr', 'dns_check_record', 'getmxrr', 'dns_get_mx', 'gethostbyaddr', 'gethostbyname',
        'gethostbynamel', 'dns_get_record',
    ];

    /** Cheap test for a file worth parsing: everything the collector looks for has one of these. */
    private const PREFILTER = '/(?:^|[;{}])\s*function\s+&?\s*[A-Za-z_\x80-\xff]|(?:^|[;{}])\s*const\s|\bdefine\s*\(|\beval\s*\(|Mock|setNamespace|-sensitive/im';

    /** @var array<lowercase-string, true> */
    private array $functions = [];

    /** @var array<non-empty-string, true> */
    private array $constants = [];

    /** @var array<string, array{lowercase-string|null, lowercase-string|null}> */
    private array $mocks = [];

    private string $namespace = '';

    /** @var list<string|null> Enclosing classes (fully qualified), null for an anonymous one. */
    private array $classes = [];

    private bool $vendor = false;
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param bool $vendor The file is in `vendor/`: only its declarations count.
     */
    public function collect(string $code, bool $vendor = false): FileShadows
    {
        if (\preg_match(self::PREFILTER, $code) !== 1) {
            return new FileShadows();
        }

        try {
            /** @var array<array-key, Node> $stmts */
            $stmts = $this->parser->parse($code) ?? [];
        } catch (Error) {
            # Not valid PHP (a template, newer syntax): it declares nothing PHP could load.
            return new FileShadows();
        }

        return $this->collectNodes($stmts, $vendor);
    }

    /**
     * @param array<array-key, Node> $stmts Not name-resolved.
     */
    public function collectNodes(array $stmts, bool $vendor = false): FileShadows
    {
        $this->reset($vendor);
        # Names are resolved in a pass of their own: the arguments of a call are resolved only when
        # the traverser enters them, after the call itself.
        $stmts = (new NodeTraverser(new NameResolver()))->traverse($stmts);
        (new NodeTraverser($this))->traverse($stmts);

        $functions = \array_keys($this->functions);
        $constants = \array_keys($this->constants);
        \sort($functions, \SORT_STRING);
        \sort($constants, \SORT_STRING);
        $mocks = $this->mocks;
        \ksort($mocks, \SORT_STRING);

        return new FileShadows($functions, $constants, \array_values($mocks));
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name === null ? '' : $node->name->toString();
        } elseif ($node instanceof Stmt\ClassLike) {
            $this->classes[] = $node->namespacedName?->toString();
            $this->vendor or $this->testGroups($node);
        } elseif ($node instanceof Stmt\Function_) {
            # A function is declared when its statement runs: inside `if`, inside another function too.
            $this->functions[\strtolower($node->namespacedName?->toString() ?? $node->name->toString())] = true;
        } elseif ($node instanceof Stmt\Const_ && $this->classes === []) {
            foreach ($node->consts as $const) {
                $this->constant($this->qualify($const->name->toString()));
            }
        } elseif ($node instanceof Expr\FuncCall) {
            $this->funcCall($node);
        } elseif ($this->vendor) {
            # Mocking libraries call their own API with variables; their users are the project's tests.
            return null;
        } elseif ($node instanceof Expr\StaticCall) {
            $this->staticCall($node);
        } elseif ($node instanceof Expr\MethodCall) {
            $this->methodCall($node);
        } elseif ($node instanceof Expr\New_ && $node->class instanceof Name
            && \strtolower($node->class->toString()) === 'phpmock\mock'
        ) {
            $this->mock($this->string($node->args[0] ?? null), $this->string($node->args[1] ?? null));
        } elseif ($node instanceof Expr\Eval_) {
            $this->evaluated($node->expr);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $node instanceof Stmt\ClassLike and \array_pop($this->classes);

        return null;
    }

    private function reset(bool $vendor): void
    {
        $this->functions = $this->constants = $this->mocks = $this->classes = [];
        $this->namespace = '';
        $this->vendor = $vendor;
    }

    private function funcCall(Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Name || \strtolower($node->name->getLast()) !== 'define') {
            return;
        }

        $name = $this->string($node->args[0] ?? null);
        # A runtime name cannot be known; such defines are global configuration in practice.
        $name === null or $name === '' or $this->constant(\ltrim($name, '\\'));
    }

    private function staticCall(Expr\StaticCall $node): void
    {
        if (!$node->class instanceof Name || !$node->name instanceof Node\Identifier) {
            return;
        }

        $class = \strtolower($node->class->toString());
        $method = $node->name->toLowerString();
        $short = \substr($class, (int) \strrpos('\\' . $class, '\\'));
        if ($method === 'register' && ($short === 'clockmock' || $short === 'dnsmock')) {
            $functions = $short === 'clockmock' ? self::CLOCK_FUNCTIONS : self::DNS_FUNCTIONS;
            foreach ($this->registeredNamespaces($this->className($node->args[0] ?? null)) as $namespace) {
                foreach ($functions as $function) {
                    $this->mock($namespace, $function);
                }
            }
        } elseif (\in_array($method, ['definefunctionmock', 'getfunctionmock'], true)
            || ($method === 'mock' && $short === 'phpmockery')
        ) {
            $this->mock($this->string($node->args[0] ?? null), $this->string($node->args[1] ?? null));
        }
    }

    private function methodCall(Expr\MethodCall $node): void
    {
        if (!$node->name instanceof Node\Identifier) {
            return;
        }

        $method = $node->name->toLowerString();
        if ($method === 'getfunctionmock' || $method === 'definefunctionmock') {
            $this->mock($this->string($node->args[0] ?? null), $this->string($node->args[1] ?? null));
            return;
        }

        if ($node->getAttribute(self::class) === true) {
            return;
        }

        # `MockBuilder`: `setNamespace()` and `setName()` anywhere in one chain. The outermost call of
        # a chain is entered first, so the whole chain is read here once; a namespace without a name
        # in the chain (set in another statement) covers every function of the namespace.
        $namespace = $name = null;
        $found = false;
        for ($chain = $node; $chain instanceof Expr\MethodCall; $chain = $chain->var) {
            $chain->setAttribute(self::class, true);
            $link = $chain->name instanceof Node\Identifier ? $chain->name->toLowerString() : '';
            if ($link === 'setnamespace') {
                $found = true;
                $namespace = $this->string($chain->args[0] ?? null);
            } elseif ($link === 'setname') {
                $name = $this->string($chain->args[0] ?? null);
            }
        }

        $found and $this->mock($namespace, $name);
    }

    /**
     * `@group time-sensitive` / `#[Group('time-sensitive')]` on a test class: the Symfony listener
     * registers ClockMock (DnsMock) for the test class.
     */
    private function testGroups(Stmt\ClassLike $node): void
    {
        $text = (string) $node->getDocComment()?->getText();
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                \strtolower($attribute->name->getLast()) === 'group' and $text .= ' ' . ($this->string($attribute->args[0] ?? null) ?? '');
            }
        }

        foreach (['time-sensitive' => self::CLOCK_FUNCTIONS, 'dns-sensitive' => self::DNS_FUNCTIONS] as $group => $functions) {
            if (!\str_contains($text, $group)) {
                continue;
            }

            foreach ($this->registeredNamespaces($node->namespacedName?->toString()) as $namespace) {
                foreach ($functions as $function) {
                    $this->mock($namespace, $function);
                }
            }
        }
    }

    /**
     * `eval()` of code that declares functions: a literal is parsed; otherwise the literal parts
     * give what they can (a `namespace X;`, `function name(`), the rest is unknown.
     */
    private function evaluated(Expr $code): void
    {
        $parts = $this->literalParts($code);
        $text = \implode("\x00", $parts);
        if (\preg_match('/\bfunction\b/i', $text) !== 1) {
            return;
        }

        if ($code instanceof Scalar\String_) {
            $inner = (new self())->collect('<?php ' . $code->value);
            foreach ($inner->functions as $function) {
                $this->functions[$function] = true;
            }

            foreach ($inner->constants as $constant) {
                $this->constants[$constant] = true;
            }

            return;
        }

        $namespace = \preg_match('/\bnamespace\s+([\w\\\\]+)\s*[;{]/', $text, $m) === 1 ? \strtolower($m[1]) : null;
        $names = \preg_match_all('/(?:^|[;{}\x00])\s*function\s+&?\s*(\w+)\s*\(/i', $text, $all) > 0 ? $all[1] : [null];
        \preg_match('/\bnamespace\b/', $text) === 1 || $namespace !== null or $namespace = '';
        foreach ($names as $name) {
            $this->mock($namespace, $name === null ? null : \strtolower($name));
        }
    }

    /**
     * @return list<string>
     */
    private function literalParts(Expr $expr): array
    {
        return match (true) {
            $expr instanceof Scalar\String_ => [$expr->value],
            $expr instanceof Scalar\InterpolatedString => \array_values(\array_map(
                static fn(Node $part): string => $part instanceof Node\InterpolatedStringPart ? $part->value : "\x00",
                $expr->parts,
            )),
            $expr instanceof Expr\BinaryOp\Concat => [...$this->literalParts($expr->left), "\x00", ...$this->literalParts($expr->right)],
            default => ["\x00"],
        };
    }

    /**
     * Namespaces `ClockMock::register($class)` mocks: the class's namespace and, for a test class,
     * the namespace without `Tests\` (symfony/phpunit-bridge).
     *
     * @return list<string|null> null — unknown class.
     */
    private function registeredNamespaces(?string $class): array
    {
        if ($class === null) {
            return [null];
        }

        $class = \strtolower(\ltrim($class, '\\'));
        $namespaces = [(string) \substr($class, 0, \max(0, (int) \strrpos($class, '\\')))];
        if (\strpos($class, '\\tests\\') > 0) {
            $stripped = \str_replace('\\tests\\', '\\', $class);
            $namespaces[] = (string) \substr($stripped, 0, \max(0, (int) \strrpos($stripped, '\\')));
        } elseif (\str_starts_with($class, 'tests\\')) {
            $namespaces[] = (string) \substr($class, 6, \max(0, (int) \strrpos($class, '\\') - 6));
        }

        return \array_values(\array_unique($namespaces));
    }

    /**
     * @param string|null $namespace null — any.
     * @param string|null $function null — any.
     */
    private function mock(?string $namespace, ?string $function): void
    {
        $function = $function === null ? null : \ltrim($function, '\\');
        $at = $function === null ? false : \strrpos($function, '\\');
        if ($function !== null && $at !== false) {
            # `getFunctionMock(null, 'App\time')`-like: the name carries the namespace.
            $namespace = \substr($function, 0, $at);
            $function = \substr($function, $at + 1);
        }

        $namespace = $namespace === null ? null : \strtolower(\trim($namespace, '\\'));
        $function = $function === null || $function === '' ? null : \strtolower($function);
        $this->mocks[($namespace ?? '*') . '|' . ($function ?? '*')] = [$namespace, $function];
    }

    private function constant(string $name): void
    {
        $at = \strrpos($name, '\\');
        $name = $at === false ? $name : \strtolower(\substr($name, 0, $at)) . \substr($name, $at);
        $name === '' or $this->constants[$name] = true;
    }

    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace . '\\' . $name;
    }

    /**
     * A string argument: a literal, `__NAMESPACE__`, `__NAMESPACE__ . '\x'`; null when unknown.
     */
    private function string(?Node $arg): ?string
    {
        $value = $arg instanceof Node\Arg ? $arg->value : $arg;

        if ($value instanceof Expr\BinaryOp\Concat) {
            $left = $this->string($value->left);
            $right = $this->string($value->right);

            return $left === null || $right === null ? null : $left . $right;
        }

        return match (true) {
            $value instanceof Scalar\String_ => $value->value,
            $value instanceof Scalar\MagicConst\Namespace_ => $this->namespace,
            $value instanceof Expr\ClassConstFetch => $this->className($value),
            default => null,
        };
    }

    /**
     * A class argument: `X::class`, `self::class`, `__CLASS__`, a literal; null when unknown.
     */
    private function className(?Node $arg): ?string
    {
        $value = $arg instanceof Node\Arg ? $arg->value : $arg;
        if ($value instanceof Expr\ClassConstFetch && $value->name instanceof Node\Identifier
            && $value->name->toLowerString() === 'class' && $value->class instanceof Name
        ) {
            $special = \strtolower($value->class->toString());

            return \in_array($special, ['self', 'static'], true) ? $this->currentClass() : $value->class->toString();
        }

        if ($value instanceof Scalar\MagicConst\Class_) {
            return $this->currentClass();
        }

        return $value instanceof Scalar\String_ ? $value->value : null;
    }

    private function currentClass(): ?string
    {
        return $this->classes === [] ? null : $this->classes[\count($this->classes) - 1];
    }
}
