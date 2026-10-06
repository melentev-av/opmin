<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

/**
 * A dynamic construct that makes "obvious" simplifications of a function wrong, found by
 * {@see DynamicScopeDetector}; written to the `count` report (`"flags": [...]`) and passed to the
 * LLM stage.
 *
 * @internal
 */
enum Flag: string
{
    /**
     * `compact()`: variables are read by name.
     */
    case Compact = 'compact';

    /**
     * `extract()`: variables are created by name.
     */
    case Extract = 'extract';

    /**
     * `$$name`, `${'name'}`.
     */
    case VariableVariable = 'variable_variable';

    /**
     * `get_defined_vars()`: the result is the set of local variables.
     */
    case DefinedVars = 'defined_vars';

    /**
     * `func_get_args()`, `func_get_arg()`, `func_num_args()`.
     */
    case FuncArgs = 'func_args';

    /**
     * `debug_backtrace()`, `debug_print_backtrace()`, `->getTrace()`, `->getTraceAsString()`.
     */
    case Backtrace = 'backtrace';

    /**
     * `__LINE__`, `__FUNCTION__`, `__METHOD__`.
     */
    case MagicConstant = 'magic_constant';

    /**
     * `eval()`: evaluated code sees the local scope.
     */
    case Eval = 'eval';

    /**
     * `include`/`require` inside the function: the included file sees the local scope.
     */
    case Include = 'include';

    /**
     * `static $var`: state between calls.
     */
    case StaticVar = 'static_var';

    /**
     * `global $x`, `$GLOBALS`.
     */
    case Global = 'global';

    /**
     * The function can be called by name from somewhere (`'fn'`, `[$obj, 'm']`, `$obj->$m()`).
     */
    case CalledDynamically = 'called_dynamically';

    /**
     * Magic members are involved: the class has `__get`/`__call`/`ArrayAccess`… or the body checks object members with `isset`/`??`.
     */
    case Magic = 'magic';

    /**
     * The function or its class is inspected through `Reflection*`.
     */
    case Reflection = 'reflection';

    /**
     * I/O, processes, network, sessions, headers.
     */
    case Io = 'io';

    /**
     * Current time: `time()`, `date()`, `new DateTime()`…
     */
    case Time = 'time';

    /**
     * Randomness: `rand()`, `random_int()`, `shuffle()`, `uniqid()`…
     */
    case Random = 'random';

    /**
     * Process environment: `getenv()`, `memory_get_usage()`, `gc_*()`, `spl_object_id()`…
     */
    case Environment = 'environment';

    /**
     * Restrictions of a set of flags, unique, in declaration order.
     *
     * @param iterable<self> $flags
     * @return list<Restriction>
     */
    public static function restrictionsOf(iterable $flags): array
    {
        $set = [];
        foreach ($flags as $flag) {
            foreach ($flag->restrictions() as $restriction) {
                $set[$restriction->value] = true;
            }
        }

        return \array_values(\array_filter(Restriction::cases(), static fn(Restriction $r): bool => isset($set[$r->value])));
    }

    /**
     * Flags from their report values; unknown values (a newer opmin) are dropped.
     *
     * @param list<string> $values
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $flags = [];
        foreach ($values as $value) {
            $flag = self::tryFrom($value);
            $flag === null or $flags[] = $flag;
        }

        return $flags;
    }

    /**
     * Report values of a set of flags: unique, in declaration order.
     *
     * @param iterable<self> $flags
     * @return list<non-empty-string>
     */
    public static function values(iterable $flags): array
    {
        $set = [];
        foreach ($flags as $flag) {
            $set[$flag->value] = true;
        }

        return \array_values(\array_map(
            static fn(self $f): string => $f->value,
            \array_filter(self::cases(), static fn(self $f): bool => isset($set[$f->value])),
        ));
    }

    /**
     * @return list<Restriction>
     */
    public function restrictions(): array
    {
        return match ($this) {
            self::Compact, self::Extract, self::VariableVariable => [Restriction::Variables],
            self::DefinedVars => [Restriction::Variables, Restriction::VariableSet],
            self::FuncArgs => [Restriction::Signature, Restriction::ReassignParams],
            self::Backtrace, self::MagicConstant => [Restriction::Inline, Restriction::LineSensitive],
            self::Eval, self::Include => [Restriction::Skip],
            self::StaticVar => [Restriction::StaticChain],
            self::Global => [Restriction::Globals, Restriction::SideEffecting],
            self::CalledDynamically => [Restriction::Signature],
            self::Magic => [Restriction::IssetCompare],
            self::Reflection => [Restriction::Signature, Restriction::Docblock],
            self::Io => [Restriction::SideEffecting],
            self::Time, self::Random, self::Environment => [Restriction::Nondeterministic],
        };
    }
}
