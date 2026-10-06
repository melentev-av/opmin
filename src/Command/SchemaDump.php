<?php

declare(strict_types=1);

namespace Opmin\Command;

use Opmin\Info;
use Opmin\Module\Config\JsonSchemaGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Regenerates `resources/opmin.schema.json` from the config DTOs (development command).
 *
 * @internal
 */
#[AsCommand(
    name: 'schema:dump',
    description: 'Regenerate the JSON Schema of opmin.yaml from the config classes',
    hidden: true,
)]
final class SchemaDump extends Command
{
    public const TARGET = Info::ROOT_DIR . '/resources/opmin.schema.json';

    public static function getCommandName(): string
    {
        return 'schema:dump';
    }

    protected function configure(): void
    {
        $this->addOption('stdout', null, InputOption::VALUE_NONE, 'Print the schema instead of writing the file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = JsonSchemaGenerator::toJson();

        if ($input->getOption('stdout')) {
            $output->write($json, false, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        \file_put_contents(self::TARGET, $json);
        $path = \realpath(self::TARGET);
        $output->writeln('<info>Written ' . ($path === false ? self::TARGET : $path) . '</info>');

        return Command::SUCCESS;
    }
}
