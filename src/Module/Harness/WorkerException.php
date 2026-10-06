<?php

declare(strict_types=1);

namespace Opmin\Module\Harness;

/**
 * The worker could not answer: it did not start, timed out, crashed, or broke the protocol.
 *
 * @internal
 */
final class WorkerException extends \RuntimeException
{
    public const START = 'start';
    public const TIMEOUT = 'timeout';
    public const CRASH = 'crash';
    public const PROTOCOL = 'protocol';

    /**
     * @param self::* $reason
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $stderr = '',
    ) {
        parent::__construct($message . ($stderr === '' ? '' : "\n" . $stderr));
    }
}
