<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Output\GithubFormatter;
use B7S\Catraca\Output\HumanFormatter;
use B7S\Catraca\Output\JsonFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

trait CommandHelper
{
    protected function addStandardOptions(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project root path', getcwd())
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: human, json, github', 'human')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text output (no ANSI colors)');
    }

    private function resolveProjectRoot(InputInterface $input, OutputInterface $output): ?string
    {
        $pathOption = $input->getOption('path');
        $rawPath = is_string($pathOption) ? $pathOption : (string) getcwd();
        $projectRoot = realpath($rawPath);
        if ($projectRoot === false || ! is_dir($projectRoot)) {
            $output->writeln(sprintf('<error>Directory not found: %s</error>', $rawPath));

            return null;
        }

        return $projectRoot;
    }

    private function resolveFormat(InputInterface $input, OutputInterface $output): string
    {
        /** @var string $format */
        $format = $input->getOption('format');

        return $format;
    }

    private function isPlainOutput(InputInterface $input, OutputInterface $output): bool
    {
        return $input->getOption('plain') || ! $output->isDecorated();
    }

    private function formatResult(InputInterface $input, OutputInterface $output, CheckResult $result): void
    {
        $format = $this->resolveFormat($input, $output);

        $formatted = match ($format) {
            'json' => (new JsonFormatter)->format($result),
            'github' => (new GithubFormatter)->format($result),
            'human' => $this->isPlainOutput($input, $output)
                ? (new HumanFormatter)->formatPlain($result)
                : (new HumanFormatter)->format($result),
            default => (new HumanFormatter)->format($result),
        };

        $output->write($formatted);
    }
}
