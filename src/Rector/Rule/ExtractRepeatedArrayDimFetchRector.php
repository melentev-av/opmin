<?php

declare(strict_types=1);

namespace Opmin\Rector\Rule;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Moves a repeated read of an array element with a constant key into a local variable: every
 * `$a['k']` is a `FETCH_DIM_R` (brief, rule 2).
 *
 * Fires only when:
 * - `$a` is a local variable whose native type is an array (an ArrayAccess object would call
 *   `offsetGet()` on every read), not bound by reference;
 * - the key provably exists (an array shape of native types, `isset()`/`array_key_exists()` before
 *   the read): a missing key warns on every read, after the change only once;
 * - no user code may run between the reads (an element may be a reference changed by a call) and
 *   nothing writes to `$a`.
 *
 * @internal
 */
#[TestRectorFixtures('Fixture/ExtractRepeatedArrayDimFetch')]
final class ExtractRepeatedArrayDimFetchRector extends AbstractExtractRepeatedReadRector
{
    public static function alias(): string
    {
        return 'array_dim_fetch';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Move a repeated read of an array element into a local variable', [
            new ConfiguredCodeSample(
                <<<'PHP'
                    $config = ['host' => $host, 'port' => $port];
                    return $config['host'] . ':' . $config['port'] . ' (' . $config['host'] . ')';
                    PHP,
                <<<'PHP'
                    $config = ['host' => $host, 'port' => $port];
                    $host2 = $config['host'];
                    return $host2 . ':' . $config['port'] . ' (' . $host2 . ')';
                    PHP,
                [self::MIN_READS => 2],
            ),
        ]);
    }

    public function match(Expr $expr): ?array
    {
        if (!$expr instanceof Expr\ArrayDimFetch || !$expr->var instanceof Expr\Variable
            || !\is_string($expr->var->name) || $expr->var->name === '' || $expr->var->name === 'this'
        ) {
            return null;
        }

        return match (true) {
            $expr->dim instanceof Scalar\String_ => ['$' . $expr->var->name . '[' . \var_export($expr->dim->value, true) . ']', $expr->var->name],
            $expr->dim instanceof Scalar\Int_ => ['$' . $expr->var->name . '[' . $expr->dim->value . ']', $expr->var->name],
            default => null,
        };
    }

    public function safe(Expr $read): bool
    {
        if (!$read instanceof Expr\ArrayDimFetch || $this->match($read) === null) {
            return false;
        }

        $type = $this->nativeType($read->var);
        $key = $read->dim instanceof Scalar\String_ ? new ConstantStringType($read->dim->value) : null;
        $read->dim instanceof Scalar\Int_ and $key = new ConstantIntegerType($read->dim->value);

        return $type !== null && $key !== null && $type->isArray()->yes() && $type->hasOffsetValueType($key)->yes();
    }

    public function stable(Expr $read): bool
    {
        return false;
    }

    public function name(Expr $read): string
    {
        if (!$read instanceof Expr\ArrayDimFetch || !$read->var instanceof Expr\Variable || !\is_string($read->var->name)) {
            return 'value';
        }

        if ($read->dim instanceof Scalar\String_) {
            # `user_id`, `user-id` → `userId`.
            $name = \lcfirst(\str_replace(' ', '', \ucwords((string) \preg_replace('/[^A-Za-z0-9\x80-\xff]+/', ' ', $read->dim->value))));
            if ($name !== '' && !\ctype_digit($name[0])) {
                return $name;
            }
        }

        $suffix = $read->dim instanceof Scalar\Int_ ? (string) $read->dim->value : '';

        return $read->var->name . ($suffix === '' ? 'Value' : $suffix);
    }
}
