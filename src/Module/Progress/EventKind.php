<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

/**
 * What happened in a run: the start of a phase, a step done, a warning.
 *
 * @internal
 */
enum EventKind
{
    /**
     * The target files are counted at the start of the run.
     */
    case Counting;

    /**
     * The project's tests on the original code (once per run).
     */
    case OriginalTests;

    /**
     * The coverage map of the project's tests (once per run, at the first verification).
     */
    case CoverageMap;

    /**
     * A rule starts in a pass.
     */
    case Pass;

    /**
     * Rector applies the rule of the step.
     */
    case Rector;

    /**
     * A round of the verification of a step: the first one, or another after a take-back.
     */
    case Verifying;

    /**
     * The verification of what the review left of a step.
     */
    case ReviewLeft;

    /**
     * PHPStan on the changed code.
     */
    case PhpStan;

    /**
     * The project's tests on the changed code.
     */
    case Tests;

    /**
     * The differential tests of a round start.
     */
    case DiffTests;

    /**
     * The differential test of one function starts.
     */
    case DiffTest;

    /**
     * The differential test of one function is done.
     */
    case DiffDone;

    /**
     * A step is written or dropped: kept, rolled back, the gain.
     */
    case StepDone;

    /**
     * The full run of the project's tests at the end (again after a step is taken back).
     */
    case FinalTests;

    /**
     * Something the user should see now, not only in the report.
     */
    case Warning;
}
