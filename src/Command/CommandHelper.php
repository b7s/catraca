<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\CheckResult;
use B7S\Catraca\FixResult;
use B7S\Catraca\Output\FixHumanFormatter;
use B7S\Catraca\Output\FixJsonFormatter;
use B7S\Catraca\Output\GithubFormatter;
use B7S\Catraca\Output\HumanFormatter;
use B7S\Catraca\Output\JsonFormatter;
use B7S\Catraca\ProjectResolver;
use JsonException;
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
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: human, json, json-pretty, github', 'human')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text output (no ANSI colors)');
    }

    private function resolveProjectRoot(InputInterface $input, OutputInterface $output): ?string
    {
        $pathOption = $input->getOption('path');
        $rawPath = is_string($pathOption) ? $pathOption : null;
        $resolver = new ProjectResolver;
        $projectRoot = $resolver->resolve($rawPath);

        if ($projectRoot === null) {
            $output->writeln(sprintf('<error>Directory not found: %s</error>', $rawPath ?? (string) getcwd()));

            return null;
        }

        return $projectRoot;
    }

    private function resolveFormat(InputInterface $input): string
    {
        /** @var string $format */
        $format = $input->getOption('format');

        return $format;
    }

    private function isPlainOutput(InputInterface $input, OutputInterface $output): bool
    {
        return $input->getOption('plain') || ! $output->isDecorated();
    }

    /**
     * @throws JsonException
     */
    private function formatResult(InputInterface $input, OutputInterface $output, CheckResult $result): void
    {
        $format = $this->resolveFormat($input);

        $formatted = match ($format) {
            'json' => (new JsonFormatter)->format($result),
            'json-pretty' => (new JsonFormatter)->format($result, true),
            'github' => (new GithubFormatter)->format($result),
            'human' => $this->isPlainOutput($input, $output)
                ? (new HumanFormatter)->formatPlain($result)
                : (new HumanFormatter)->format($result),
            default => (new HumanFormatter)->format($result),
        };

        $output->write($formatted);
    }

    /**
     * @throws JsonException
     */
    private function formatFixResult(InputInterface $input, OutputInterface $output, FixResult $result): void
    {
        $format = $this->resolveFormat($input);

        $formatted = match ($format) {
            'json' => (new FixJsonFormatter)->format($result),
            'json-pretty' => (new FixJsonFormatter)->format($result, true),
            'human' => $this->isPlainOutput($input, $output)
                ? (new FixHumanFormatter)->formatPlain($result)
                : (new FixHumanFormatter)->format($result),
            default => (new FixHumanFormatter)->format($result),
        };

        $output->write($formatted);
    }
}
