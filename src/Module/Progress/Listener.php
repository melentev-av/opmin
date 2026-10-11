<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

/**
 * Receives the progress of a run: the optimizer, the verifier and the pool of differential tests send
 * {@see Event}s, the command decides how they look ({@see Renderer}).
 *
 * @internal
 */
interface Listener
{
    public function on(Event $event): void;
}
