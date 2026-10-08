<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Finds the dynamic constructs in the body of one function (table 2.2 of the brief): flags that
 * forbid "obvious" simplifications and tell the differential tester what to do.
 *
 * Only the function's own body is scanned: a nested closure, function or class is a unit of its own.
 * The flags the function gets from the rest of the project — {@see Flag::CalledDynamically},
 * {@see Flag::Reflection} by another file — come from {@see ReferenceIndex}.
 *
 * Expects an AST processed by `NameResolver`.
 *
 * @internal
 */
final class DynamicScopeDetector
{
    /** @var array<lowercase-string, Flag> Global functions that set a flag. */
    private const FUNCTIONS = [
        'compact' => Flag::Compact,
        'extract' => Flag::Extract,
        'get_defined_vars' => Flag::DefinedVars,
        'func_get_args' => Flag::FuncArgs,
        'func_get_arg' => Flag::FuncArgs,
        'func_num_args' => Flag::FuncArgs,
        'debug_backtrace' => Flag::Backtrace,
        'debug_print_backtrace' => Flag::Backtrace,
        # Time.
        'time' => Flag::Time, 'microtime' => Flag::Time, 'hrtime' => Flag::Time, 'date' => Flag::Time,
        'gmdate' => Flag::Time, 'idate' => Flag::Time, 'mktime' => Flag::Time, 'gmmktime' => Flag::Time,
        'strtotime' => Flag::Time, 'getdate' => Flag::Time, 'localtime' => Flag::Time, 'strftime' => Flag::Time,
        'gmstrftime' => Flag::Time, 'gettimeofday' => Flag::Time, 'date_create' => Flag::Time,
        'date_create_immutable' => Flag::Time, 'sleep' => Flag::Time, 'usleep' => Flag::Time,
        'time_nanosleep' => Flag::Time, 'time_sleep_until' => Flag::Time,
        # Randomness.
        'rand' => Flag::Random, 'mt_rand' => Flag::Random, 'random_int' => Flag::Random, 'random_bytes' => Flag::Random,
        'shuffle' => Flag::Random, 'str_shuffle' => Flag::Random, 'array_rand' => Flag::Random, 'uniqid' => Flag::Random,
        'lcg_value' => Flag::Random, 'mt_srand' => Flag::Random, 'srand' => Flag::Random,
        'openssl_random_pseudo_bytes' => Flag::Random,
        # Environment.
        'getenv' => Flag::Environment, 'putenv' => Flag::Environment, 'php_uname' => Flag::Environment,
        'memory_get_usage' => Flag::Environment, 'memory_get_peak_usage' => Flag::Environment,
        'memory_reset_peak_usage' => Flag::Environment, 'gc_collect_cycles' => Flag::Environment,
        'gc_status' => Flag::Environment, 'gc_mem_caches' => Flag::Environment, 'getmypid' => Flag::Environment,
        'getmyuid' => Flag::Environment, 'getrusage' => Flag::Environment, 'sys_getloadavg' => Flag::Environment,
        'gethostname' => Flag::Environment, 'spl_object_id' => Flag::Environment, 'spl_object_hash' => Flag::Environment,
        'set_time_limit' => Flag::Environment, 'ini_set' => Flag::Environment, 'ini_get' => Flag::Environment,
        'setlocale' => Flag::Environment, 'set_error_handler' => Flag::Environment,
        'set_exception_handler' => Flag::Environment, 'error_reporting' => Flag::Environment,
        # I/O, processes, network.
        'fopen' => Flag::Io, 'fwrite' => Flag::Io, 'fputs' => Flag::Io, 'fread' => Flag::Io, 'fgets' => Flag::Io,
        'fgetc' => Flag::Io, 'fgetcsv' => Flag::Io, 'fputcsv' => Flag::Io, 'fclose' => Flag::Io, 'fflush' => Flag::Io,
        'file' => Flag::Io, 'file_get_contents' => Flag::Io, 'file_put_contents' => Flag::Io, 'file_exists' => Flag::Io,
        'is_file' => Flag::Io, 'is_dir' => Flag::Io, 'is_readable' => Flag::Io, 'is_writable' => Flag::Io,
        'filemtime' => Flag::Io, 'filesize' => Flag::Io, 'readfile' => Flag::Io, 'unlink' => Flag::Io,
        'mkdir' => Flag::Io, 'rmdir' => Flag::Io, 'rename' => Flag::Io, 'copy' => Flag::Io, 'touch' => Flag::Io,
        'chmod' => Flag::Io, 'chown' => Flag::Io, 'tempnam' => Flag::Io, 'tmpfile' => Flag::Io, 'scandir' => Flag::Io,
        'glob' => Flag::Io, 'opendir' => Flag::Io, 'readdir' => Flag::Io, 'realpath' => Flag::Io, 'flock' => Flag::Io,
        'parse_ini_file' => Flag::Io, 'mail' => Flag::Io, 'header' => Flag::Io, 'header_remove' => Flag::Io,
        'setcookie' => Flag::Io, 'setrawcookie' => Flag::Io, 'http_response_code' => Flag::Io, 'exec' => Flag::Io,
        'shell_exec' => Flag::Io, 'system' => Flag::Io, 'passthru' => Flag::Io, 'popen' => Flag::Io,
        'error_log' => Flag::Io, 'syslog' => Flag::Io, 'openlog' => Flag::Io, 'fsockopen' => Flag::Io,
        'pfsockopen' => Flag::Io, 'gethostbyname' => Flag::Io, 'dns_get_record' => Flag::Io,
        'move_uploaded_file' => Flag::Io, 'register_shutdown_function' => Flag::Io,
    ];

    /** Prefixes of extension functions that do I/O. */
    private const IO_PREFIXES = [
        'curl_', 'socket_', 'stream_', 'mysqli_', 'pg_', 'sqlite_', 'oci_', 'odbc_', 'session_', 'posix_', 'pcntl_',
        'proc_', 'ftp_', 'ssh2_', 'shm_', 'shmop_', 'sem_', 'msg_', 'apcu_', 'ldap_', 'imap_', 'gzopen', 'gzwrite',
        'gzread', 'gzfile', 'bzopen', 'zip_', 'xmlwriter_open', 'fseek', 'ftell', 'ftruncate', 'rewind', 'feof',
    ];

    /** @var array<lowercase-string, Flag> Classes whose instantiation sets a flag. */
    private const CLASSES = [
        'pdo' => Flag::Io, 'mysqli' => Flag::Io, 'splfileobject' => Flag::Io, 'splfileinfo' => Flag::Io,
        'directoryiterator' => Flag::Io, 'filesystemiterator' => Flag::Io, 'recursivedirectoryiterator' => Flag::Io,
        'globiterator' => Flag::Io, 'sqlite3' => Flag::Io, 'ziparchive' => Flag::Io, 'phar' => Flag::Io,
        'datetime' => Flag::Time, 'datetimeimmutable' => Flag::Time, 'random\randomizer' => Flag::Random,
    ];

    /** Magic methods that make member access go through user code. */
    private const MAGIC_METHODS = ['__get', '__set', '__isset', '__unset', '__call', '__callstatic'];

    /** Interfaces whose methods are called by `$obj[...]`, `isset($obj[...])`. */
    private const MAGIC_INTERFACES = ['arrayaccess'];

    /** @var array<non-empty-string, Flag> */
    private array $flags = [];

    private ?Stmt\ClassLike $class = null;

    /**
     * Whether the class routes member access through user code (magic methods, `ArrayAccess`).
     */
    public static function isMagicClass(Stmt\ClassLike $class): bool
    {
        foreach ($class->getMethods() as $method) {
            if (\in_array($method->name->toLowerString(), self::MAGIC_METHODS, true)) {
                return true;
            }
        }

        $interfaces = match (true) {
            $class instanceof Stmt\Class_, $class instanceof Stmt\Enum_ => $class->implements,
            $class instanceof Stmt\Interface_ => $class->extends,
            default => [],
        };
        foreach ($interfaces as $interface) {
            if (\in_array(\strtolower($interface->toString()), self::MAGIC_INTERFACES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The global function a call can resolve to: `compact()` or `\compact()`, not `Foo\compact()`.
     *
     * @return lowercase-string|null
     */
    public static function globalFunction(Node $name): ?string
    {
        if (!$name instanceof Name || \count($name->getParts()) !== 1) {
            return null;
        }

        return $name->isUnqualified() || $name->isFullyQualified() ? $name->toLowerString() : null;
    }

    /**
     * @param Node\FunctionLike $function Function, method, closure, arrow function or property hook.
     * @param Stmt\ClassLike|null $class The class the function belongs to (methods and hooks, and
     *        closures inside them).
     * @return list<Flag> In declaration order of {@see Flag}.
     */
    public function detect(Node\FunctionLike $function, ?Stmt\ClassLike $class = null): array
    {
        $this->flags = [];
        $this->class = $class;
        $class === null || !self::isMagicClass($class) or $this->flags[Flag::Magic->value] = Flag::Magic;
        $this->walk($function instanceof Node\PropertyHook ? $function->body : $function->getStmts());
        $function instanceof Expr\ArrowFunction and $this->walk($function->expr);
        foreach ($function->getParams() as $param) {
            $this->walk($param->default);
        }

        $flags = \array_values($this->flags);
        \usort($flags, static fn(Flag $a, Flag $b): int => \array_search($a, Flag::cases(), true) <=> \array_search($b, Flag::cases(), true));

        return $flags;
    }

    private function walk(mixed $node): void
    {
        if (\is_array($node)) {
            /** @var mixed $child */
            foreach ($node as $child) {
                $this->walk($child);
            }

            return;
        }

        if (!$node instanceof Node
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\Function_
            || $node instanceof Stmt\ClassLike
        ) {
            # Not a node, or a unit of its own (an anonymous class too). Arguments of `new class(...)`
            # are evaluated here, though.
            $node instanceof Expr\New_ && $node->class instanceof Stmt\Class_ and $this->walk($node->args);
            return;
        }

        $this->inspect($node);
        foreach ($node->getSubNodeNames() as $name) {
            $name === 'attrGroups' or $this->walk($node->{$name});
        }
    }

    private function inspect(Node $node): void
    {
        match (true) {
            $node instanceof Expr\FuncCall => $this->call($node),
            $node instanceof Expr\New_ => $this->new($node),
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => $node->name instanceof Node\Identifier
                && \in_array($node->name->toLowerString(), ['gettrace', 'gettraceasstring'], true)
                && $this->add(Flag::Backtrace),
            $node instanceof Expr\Variable => \is_string($node->name)
                ? $node->name === 'GLOBALS' && $this->add(Flag::Global)
                : $this->add(Flag::VariableVariable),
            $node instanceof Node\Scalar\MagicConst\Line,
            $node instanceof Node\Scalar\MagicConst\Function_,
            $node instanceof Node\Scalar\MagicConst\Method => $this->add(Flag::MagicConstant),
            $node instanceof Expr\Eval_ => $this->add(Flag::Eval),
            $node instanceof Expr\Include_ => $this->add(Flag::Include),
            $node instanceof Stmt\Static_ => $this->add(Flag::StaticVar),
            $node instanceof Stmt\Global_ => $this->add(Flag::Global),
            $node instanceof Expr\Isset_ => $this->memberCheck($node->vars),
            $node instanceof Stmt\Unset_ => $this->memberCheck($node->vars),
            $node instanceof Expr\Empty_ => $this->memberCheck([$node->expr]),
            $node instanceof Expr\BinaryOp\Coalesce => $this->memberCheck([$node->left]),
            $node instanceof Expr\AssignOp\Coalesce => $this->memberCheck([$node->var]),
            default => null,
        };
    }

    private function call(Expr\FuncCall $node): void
    {
        $name = self::globalFunction($node->name);
        if ($name === null) {
            return;
        }

        if (isset(self::FUNCTIONS[$name])) {
            $this->add(self::FUNCTIONS[$name]);
            return;
        }

        foreach (self::IO_PREFIXES as $prefix) {
            if (\str_starts_with($name, $prefix)) {
                $this->add(Flag::Io);
                return;
            }
        }
    }

    private function new(Expr\New_ $node): void
    {
        if (!$node->class instanceof Name) {
            return;
        }

        $class = \strtolower(\ltrim($node->class->toString(), '\\'));
        if (\str_starts_with($class, 'reflection')) {
            $this->reflection($node);
            return;
        }

        $flag = self::CLASSES[$class] ?? null;
        if ($flag === null) {
            return;
        }

        # `new DateTime('2020-01-01')` is deterministic; without arguments or with 'now' it is not.
        if ($flag === Flag::Time && $node->args !== []) {
            $first = $node->args[0];
            if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_
                && !\in_array(\strtolower(\trim($first->value->value)), ['', 'now', 'today', 'tomorrow', 'yesterday'], true)
                && \preg_match('/^\d{4}-\d{2}-\d{2}/', $first->value->value) === 1
            ) {
                return;
            }
        }

        # A `Randomizer` with an explicit (seeded) engine is deterministic.
        if ($flag === Flag::Random && $node->args !== []) {
            return;
        }

        $this->add($flag);
    }

    /**
     * `new ReflectionX(...)` over the function itself or its class.
     */
    private function reflection(Expr\New_ $node): void
    {
        $own = $this->class?->namespacedName?->toLowerString();
        foreach ($node->args as $arg) {
            $value = $arg instanceof Node\Arg ? $arg->value : null;
            $self = match (true) {
                $value instanceof Expr\Variable => $value->name === 'this',
                $value instanceof Node\Scalar\MagicConst\Class_,
                $value instanceof Node\Scalar\MagicConst\Function_,
                $value instanceof Node\Scalar\MagicConst\Method => true,
                $value instanceof Expr\ClassConstFetch && $value->class instanceof Name => \in_array($value->class->toLowerString(), ['self', 'static'], true)
                    || ($own !== null && \strtolower(\ltrim($value->class->toString(), '\\')) === $own),
                $value instanceof Expr\FuncCall => \in_array(self::globalFunction($value->name), ['get_class', 'get_called_class', 'static'], true),
                default => false,
            };
            if ($self) {
                $this->add(Flag::Reflection);
                return;
            }
        }
    }

    /**
     * `isset`, `empty`, `unset` and `??` on object members call `__isset`/`__get`/`__unset`:
     * replacing them with comparisons changes which magic method runs.
     *
     * @param array<array-key, Node> $exprs
     */
    private function memberCheck(array $exprs): void
    {
        foreach ($exprs as $expr) {
            if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) {
                $this->add(Flag::Magic);
                return;
            }
        }
    }

    /**
     * @return true
     */
    private function add(Flag $flag): bool
    {
        $this->flags[$flag->value] = $flag;

        return true;
    }
}
