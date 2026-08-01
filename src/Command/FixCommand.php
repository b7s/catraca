<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\Baseline;
use B7S\Catraca\Catraca;
use B7S\Catraca\ToolResolver;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fix', description: 'Auto-fix code style and performance issues')]
class FixCommand extends ProjectCommand
{
    protected function configure(): void
    {
        $this->addStandardOptions();
        $this->addOption('no-check', null, InputOption::VALUE_NONE, 'Skip running check after fix');
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
        $baseline = new Baseline($projectRoot);
        $resolver = new ToolResolver($projectRoot);
        $result = $this->runFixers($baseline, $resolver);
        $this->formatFixResult($input, $output, $result);

        if ($input->getOption('no-check')) {
            return Command::SUCCESS;
        }

        $output->writeln('');

        $checkResult = $this->runCatraca($input, $output, $catraca);
        $this->formatResult($input, $output, $checkResult);

        return $checkResult->isPass() ? Command::SUCCESS : Command::FAILURE;
    }
}
