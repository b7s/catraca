<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\Catraca;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Run all quality gates and compare against baseline',
)]
class CheckCommand extends Command
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
        $result = $catraca->check();

        $this->formatResult($input, $output, $result);

        return $result->isPass() ? Command::SUCCESS : Command::FAILURE;
    }
}
