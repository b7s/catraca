<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\Baseline;
use B7S\Catraca\Catraca;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function json_encode;

#[AsCommand(name: 'explain', description: 'Explain the effective policy and baseline for a gate')]
final class ExplainCommand extends ProjectCommand
{
    protected function configure(): void
    {
        $this->addStandardOptions();
        $this->addArgument('gate', InputArgument::REQUIRED, 'Gate name, such as coverage or duplication');
    }

    /** @throws JsonException */
    protected function executeForProject(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        Catraca $catraca,
    ): int {
        $gate = (string) $input->getArgument('gate');
        $baseline = new Baseline($projectRoot, profile: $this->stringOption($input, 'profile') ?? 'default');
        $data = $baseline->read() ?? [];
        $profile = $baseline->getProfile();
        if ($profile === 'default') {
            $config = $data['config'][$gate] ?? [];
            $result = $data['results'][$gate] ?? [];
        } else {
            $config = $data['profiles'][$profile]['config'][$gate] ?? $data['config'][$gate] ?? [];
            $result = $data['profiles'][$profile]['results'][$gate] ?? [];
        }

        if ($config === [] && $result === []) {
            $output->writeln('<error>Unknown gate: ' . $gate . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>' . $gate . '</info>');
        $output->writeln('Mode: ' . ($config['mode'] ?? 'no_regression'));
        $output->writeln('Missing tool: ' . $baseline->getPolicy('missing_tool', 'skip'));
        $output->writeln('Unavailable metric: ' . $baseline->getPolicy('unavailable_metric', 'warn'));
        $output->writeln('Configuration: ' . json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $output->writeln('Baseline: ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
