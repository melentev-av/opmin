<?php

declare(strict_types=1);

namespace Opmin\Module\Opcode\Dump;

/**
 * Which OPcache dump a block comes from.
 *
 * @internal
 */
enum Phase: string
{
    /**
     * `opt_debug_level=0x10000`: compiled, not optimized yet (ops_raw).
     */
    case Raw = 'before optimizer';

    /**
     * `opt_debug_level=0x20000`: what is actually executed (ops_opt, the main metric).
     */
    case Opt = 'after optimizer';
}
