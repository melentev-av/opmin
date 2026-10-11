<?php

declare(strict_types=1);

namespace Opmin\Module\Progress;

/**
 * Shows nothing.
 *
 * @internal
 */
final class Silent implements Renderer
{
    #[\Override]
    public function on(Event $event): void {}

    #[\Override]
    public function clear(): void {}
}
