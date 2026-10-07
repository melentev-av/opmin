<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use Opmin\Module\Analysis\Shadow\ShadowIndex;
use Opmin\Rector\Support\FunctionBody;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Qualifies calls of global functions and global constants inside a namespace: `strlen($s)` →
 * `\strlen($s)`, `PHP_EOL` → `\PHP_EOL` (brief, rule 4). Without the backslash PHP looks for
 * `App\strlen` first at run time, and the compiler can neither turn the call into a special opcode
 * nor evaluate it.
 *
 * Only names that surely mean the global symbol are qualified: the function or constant exists in
 * `php.binary` (or is a global function/constant of the project), and nothing declares or mocks
 * one of that name in the namespace ({@see ShadowIndex}, brief 2.3). Every global function is
 * qualified, not only the ones with special opcodes: a change without an opcode gain is rolled
 * back by the pipeline anyway.
 *
 * Options:
 * - `style`: `backslash` (`\strlen()`), `use_function` (`use function strlen;`) or `auto` — the style
 *   of the file when it imports a global function already, `backslash` otherwise;
 * - `shadows`: JSON file with {@see ShadowIndex::toArray()} of the project;
 * - `symbols`: JSON file `{"functions": [...], "constants": [...]}` with the internal functions and
 *   constants of `php.binary` (default: of the PHP running Rector).
 *
 * Not final: the tests fix the options in a subclass (testo/bridge-rector passes none).
 *
 * @internal
 * @psalm-suppress ClassMustBeFinal
 */
#[TestRectorFixtures('Fixture/FullyQualifyGlobalCalls')]
class FullyQualifyGlobalCallsRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string STYLE = 'style';
    public const string SHADOWS = 'shadows';
    public const string SYMBOLS = 'symbols';

    /** Constants the compiler resolves itself in any namespace. */
    private const array SPECIAL_CONSTANTS = ['true', 'false', 'null'];

    /** @var 'auto'|'backslash'|'use_function' */
    private string $style = 'auto';

    private ShadowIndex $shadows;

    /** @var array<lowercase-string, true> */
    private array $functions = [];

    /** @var array<string, true> */
    private array $constants = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->shadows = new ShadowIndex();
        $this->useSymbols(\get_defined_functions()['internal'], self::internalConstants());
    }

    public static function alias(): string
    {
        return 'fqn';
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    public function configure(array $configuration): void
    {
        $style = $configuration[self::STYLE] ?? 'auto';
        \in_array($style, ['auto', 'backslash', 'use_function'], true) or throw new \InvalidArgumentException(
            self::class . ': `style` must be auto, backslash or use_function.',
        );
        $this->style = $style;

        # A path to the JSON file, or the array itself (tests).
        /** @var mixed $shadows */
        $shadows = $configuration[self::SHADOWS] ?? null;
        $shadows === null or $this->shadows = ShadowIndex::fromArray(\is_array($shadows) ? $shadows : self::readJson($shadows));

        /** @var mixed $symbols */
        $symbols = $configuration[self::SYMBOLS] ?? null;
        if ($symbols !== null) {
            /** @var array{functions?: list<string>, constants?: list<string>} $data */
            $data = self::readJson($symbols);
            $this->useSymbols($data['functions'] ?? [], $data['constants'] ?? []);
        }
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Fully qualify global functions and constants inside a namespace', [
            new ConfiguredCodeSample(
                'namespace App; return strlen($s) . PHP_EOL;',
                'namespace App; return \strlen($s) . \PHP_EOL;',
                [self::STYLE => 'backslash'],
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Stmt\Namespace_::class];
    }

    /**
     * @param Stmt\Namespace_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->name === null) {
            return null;
        }

        $namespace = $node->name->toString();
        $style = $this->style === 'auto' ? $this->fileStyle($node) : $this->style;
        $imports = ['function' => [], 'const' => []];
        $changed = false;
        foreach ($this->candidates($node->stmts) as $usage) {
            /** @var Name $name */
            $name = $usage->name;
            $global = $name->toString();
            $function = $usage instanceof Expr\FuncCall;
            if (!($function ? $this->qualifiesFunction($namespace, $global) : $this->qualifiesConstant($namespace, $global))) {
                continue;
            }

            if ($style === 'use_function') {
                $imports[$function ? 'function' : 'const'][$function ? \strtolower($global) : $global] = $global;
                continue;
            }

            $usage->name = new Name\FullyQualified($global);
            $changed = true;
        }

        if ($imports['function'] !== [] || $imports['const'] !== []) {
            $this->addImports($node, $imports['function'], $imports['const']);
            $changed = true;
        }

        return $changed ? $node : null;
    }

    /**
     * @return list<string>
     */
    private static function internalConstants(): array
    {
        $constants = [];
        foreach (\get_defined_constants(true) as $extension => $group) {
            $extension === 'user' or \array_push($constants, ...\array_keys($group));
        }

        return $constants;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function readJson(mixed $path): array
    {
        $raw = \is_string($path) ? @\file_get_contents($path) : false;
        $raw === false and throw new \InvalidArgumentException(self::class . ': cannot read ' . \get_debug_type($path) . ' `' . (\is_string($path) ? $path : '') . '`.');
        /** @var mixed $data */
        $data = \json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        \is_array($data) or throw new \InvalidArgumentException(self::class . ": `{$path}` is not a JSON object.");

        return $data;
    }

    /**
     * Unqualified, not imported names of called functions and fetched constants, outside the
     * functions the user excluded.
     *
     * @param array<array-key, Node> $nodes
     * @return list<Expr\FuncCall|Expr\ConstFetch>
     */
    private function candidates(array $nodes): array
    {
        $names = [];
        $stack = \array_reverse(\array_values($nodes));
        while ($stack !== []) {
            $node = \array_pop($stack);
            if ($node instanceof Node\FunctionLike && FunctionBody::ignored($node, self::alias())) {
                continue;
            }

            if (($node instanceof Expr\FuncCall || $node instanceof Expr\ConstFetch) && $node->name instanceof Name
                && $node->name->isUnqualified() && $node->name->getAttribute('resolvedName') === null
            ) {
                $names[] = $node;
            }

            $children = [];
            foreach ($node->getSubNodeNames() as $sub) {
                /** @var mixed $child */
                $child = $node->{$sub};
                if ($child instanceof Node) {
                    $children[] = $child;
                } elseif (\is_array($child)) {
                    /** @var mixed $item */
                    foreach ($child as $item) {
                        $item instanceof Node and $children[] = $item;
                    }
                }
            }

            \array_push($stack, ...\array_reverse($children));
        }

        return $names;
    }

    private function qualifiesFunction(string $namespace, string $function): bool
    {
        $lower = \strtolower($function);

        return (isset($this->functions[$lower]) || $this->shadows->hasGlobalFunction($lower) || $this->userFunction($function))
            && $this->shadows->canQualifyFunction($namespace, $lower)
            # A namespaced function the analysed code declares or autoloads.
            && !$this->reflectionProvider->hasFunction(new Name\FullyQualified($namespace . '\\' . $function), null);
    }

    /**
     * A global function of the analysed code (an internal one must exist in `php.binary`, not only
     * in PHPStan's stubs).
     */
    private function userFunction(string $function): bool
    {
        $name = new Name\FullyQualified($function);

        return $this->reflectionProvider->hasFunction($name, null) && !$this->reflectionProvider->getFunction($name, null)->isBuiltin();
    }

    private function qualifiesConstant(string $namespace, string $constant): bool
    {
        if (\in_array(\strtolower($constant), self::SPECIAL_CONSTANTS, true)) {
            return false;
        }

        return (isset($this->constants[$constant]) || $this->shadows->hasGlobalConstant($constant))
            && $this->shadows->canQualifyConstant($namespace, $constant)
            && !$this->reflectionProvider->hasConstant(new Name\FullyQualified($namespace . '\\' . $constant), null);
    }

    /**
     * `use_function` when the namespace imports a global function or constant already.
     *
     * @return 'backslash'|'use_function'
     */
    private function fileStyle(Stmt\Namespace_ $namespace): string
    {
        foreach ($namespace->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Use_ || ($stmt->type !== Stmt\Use_::TYPE_FUNCTION && $stmt->type !== Stmt\Use_::TYPE_CONSTANT)) {
                continue;
            }

            foreach ($stmt->uses as $use) {
                if (!\str_contains($use->name->toString(), '\\')) {
                    return 'use_function';
                }
            }
        }

        return 'backslash';
    }

    /**
     * @param array<string, string> $functions
     * @param array<string, string> $constants
     */
    private function addImports(Stmt\Namespace_ $namespace, array $functions, array $constants): void
    {
        \ksort($functions, \SORT_STRING);
        \ksort($constants, \SORT_STRING);
        $new = [];
        foreach ($functions as $function) {
            $new[] = new Stmt\Use_([new Node\UseItem(new Name($function))], Stmt\Use_::TYPE_FUNCTION);
        }

        foreach ($constants as $constant) {
            $new[] = new Stmt\Use_([new Node\UseItem(new Name($constant))], Stmt\Use_::TYPE_CONSTANT);
        }

        $at = 0;
        foreach ($namespace->stmts as $i => $stmt) {
            ($stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\GroupUse) and $at = $i + 1;
        }

        \array_splice($namespace->stmts, $at, 0, $new);
    }

    /**
     * @param list<string> $functions
     * @param list<string> $constants
     */
    private function useSymbols(array $functions, array $constants): void
    {
        $this->functions = [];
        foreach ($functions as $function) {
            $this->functions[\strtolower($function)] = true;
        }

        $this->constants = [];
        foreach ($constants as $constant) {
            $this->constants[$constant] = true;
        }
    }
}
