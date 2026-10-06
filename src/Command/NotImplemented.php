<?php

declare(strict_types=1);

namespace Opmin\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;

/**
 * A command that is registered but not implemented yet.
 *
 * It loads and validates the config like any command, then fails with a message naming the stage
 * that implements it — a placeholder must never look like a successful run in CI.
 *
 * @internal
 */
abstract class NotImplemented extends Base
{
    /** @var non-empty-string Stage of the plan that implements the command. */
    protected const string STAGE = 'M?';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        /** @var StyleInterface $style */
        $style = $this->container->get(StyleInterface::class);
        $style->warning(\sprintf('`%s` is not implemented yet (planned for %s).', (string) $this->getName(), static::STAGE));

        return Command::FAILURE;
    }
}
