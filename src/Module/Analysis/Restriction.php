<?php

declare(strict_types=1);

namespace Opmin\Module\Analysis;

/**
 * What an optimization (a Rector rule, the LLM) must not do with a function, and what the
 * differential tester must do with it. Derived from the {@see Flag}s of the function.
 *
 * @internal
 */
enum Restriction: string
{
    /**
     * Do not remove, rename or inline local variables.
     */
    case Variables = 'variables';

    /**
     * Do not change the set of local variables at all, not even with a new temporary.
     */
    case VariableSet = 'variable_set';

    /**
     * Do not change the signature (parameters, their types and defaults, the return type).
     */
    case Signature = 'signature';

    /**
     * Do not reassign parameters.
     */
    case ReassignParams = 'reassign_params';

    /**
     * Do not change the docblock.
     */
    case Docblock = 'docblock';

    /**
     * Do not inline the function or move code across function boundaries.
     */
    case Inline = 'inline';

    /**
     * The result depends on line numbers: keep the code on its lines.
     */
    case LineSensitive = 'line_sensitive';

    /**
     * Do not replace `isset()`/`??` on members of objects with comparisons and vice versa.
     */
    case IssetCompare = 'isset_compare';

    /**
     * Call several times in a row and compare the whole chain; keep the initialization order.
     */
    case StaticChain = 'static_chain';

    /**
     * Touches global state: compare the globals, treat as side-effecting.
     */
    case Globals = 'globals';

    /**
     * External effects (I/O, processes, network): only the project's tests can verify it.
     */
    case SideEffecting = 'side_effecting';

    /**
     * Depends on time, randomness or the environment: fakes and the determinism check decide.
     */
    case Nondeterministic = 'nondeterministic';

    /**
     * Do not optimize the function at all.
     */
    case Skip = 'skip';
}
