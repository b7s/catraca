<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\Catraca;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

#[AsCommand(name: 'init', description: 'Initialize catraca_baseline.json with current metrics')]
class InitCommand extends ProjectCommand
{
    protected function configure(): void
    {
        $this->addStandardOptions();
    }

    /**
     * @throws JsonException
     */
    protected function executeForProject(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        Catraca $catraca,
    ): int {
        $result = $this->runCatraca($input, $output, $catraca, initialize: true);
        $this->formatResult($input, $output, $result);

        $this->saveRunIfEnabled($input, $projectRoot, $result);
        $output->writeln('');
        $output->writeln(sprintf('<info>Baseline written to %s/catraca_baseline.json</info>', $projectRoot));

        return Command::SUCCESS;
    }
}
