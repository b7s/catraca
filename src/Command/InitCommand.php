<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\Catraca;
use B7S\Catraca\Output\GithubFormatter;
use B7S\Catraca\Output\HumanFormatter;
use B7S\Catraca\Output\JsonFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'init',
    description: 'Initialize baseline.json with current metrics',
)]
class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project root path', getcwd())
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: human, json, github', 'human')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text output (no ANSI colors)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pathOption = $input->getOption('path');
        $rawPath = is_string($pathOption) ? $pathOption : (string) getcwd();
        $projectRoot = realpath($rawPath);
        if ($projectRoot === false || ! is_dir($projectRoot)) {
            $output->writeln(sprintf('<error>Directory not found: %s</error>', $rawPath));

            return Command::FAILURE;
        }

        /** @var string $format */
        $format = $input->getOption('format');
        $noAnsi = $input->getOption('plain') || ! $output->isDecorated();

        $catraca = new Catraca($projectRoot);
        $result = $catraca->init();

        $formatted = match ($format) {
            'json' => (new JsonFormatter)->format($result),
            'github' => (new GithubFormatter)->format($result),
            'human' => $noAnsi
                ? (new HumanFormatter)->formatPlain($result)
                : (new HumanFormatter)->format($result),
            default => (new HumanFormatter)->format($result),
        };

        $output->write($formatted);

        $output->writeln('');
        $output->writeln(sprintf('<info>Baseline written to %s/baseline.json</info>', $projectRoot));

        return Command::SUCCESS;
    }
}
