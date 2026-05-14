<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\Catraca;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(
    name: 'init',
    description: 'Initialize catraca_baseline.json with current metrics',
)]
class InitCommand extends Command
{
    use CommandHelper;

    protected function configure(): void
    {
        $this->addStandardOptions();
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($input, $output);
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $catraca = new Catraca($projectRoot);
        $result = $catraca->init();

        $this->formatResult($input, $output, $result);

        $output->writeln('');
        $output->writeln(sprintf('<info>Baseline written to %s/catraca_baseline.json</info>', $projectRoot));

        return Command::SUCCESS;
    }
}
