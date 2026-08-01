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

#[AsCommand(name: 'check', description: 'Run all quality gates and compare against baseline')]
class CheckCommand extends ProjectCommand
{
    protected function configure(): void
    {
        $this->addStandardOptions();
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Auto-fix issues if any gate fails');
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
        $result = $this->runCatraca($input, $output, $catraca);
        $this->formatResult($input, $output, $result);

        if ($result->isPass() || !$input->getOption('fix')) {
            $this->saveRunIfEnabled($input, $projectRoot, $result);

            return $result->isPass() ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln('');

        $baseline = new Baseline($projectRoot);
        $resolver = new ToolResolver($projectRoot);
        $fixResult = $this->runFixers($baseline, $resolver);
        $this->formatFixResult($input, $output, $fixResult);

        $output->writeln('');

        $recheckResult = $this->runCatraca($input, $output, $catraca);
        $this->formatResult($input, $output, $recheckResult);
        $this->saveRunIfEnabled($input, $projectRoot, $recheckResult);

        return $recheckResult->isPass() ? Command::SUCCESS : Command::FAILURE;
    }
}
