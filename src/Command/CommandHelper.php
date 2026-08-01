<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\AgentDetector;
use B7S\Catraca\Baseline;
use B7S\Catraca\Catraca;
use B7S\Catraca\CheckResult;
use B7S\Catraca\Fixer\AutoloadFixer;
use B7S\Catraca\Fixer\CodeStyleFixer;
use B7S\Catraca\Fixer\ConditionOrderFixer;
use B7S\Catraca\Fixer\FixerInterface;
use B7S\Catraca\Fixer\PerformanceFixer;
use B7S\Catraca\FixResult;
use B7S\Catraca\Output\FixHumanFormatter;
use B7S\Catraca\Output\FixJsonFormatter;
use B7S\Catraca\Output\GithubFormatter;
use B7S\Catraca\Output\HumanFormatter;
use B7S\Catraca\Output\JsonFormatter;
use B7S\Catraca\Output\JunitFormatter;
use B7S\Catraca\Output\LiveCheckRenderer;
use B7S\Catraca\Output\SarifFormatter;
use B7S\Catraca\ProjectResolver;
use B7S\Catraca\RunHistoryStore;
use B7S\Catraca\ToolResolver;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function getcwd;
use function is_numeric;
use function is_string;
use function sprintf;

trait CommandHelper
{
    private bool $liveCheckRendered = false;

    /** @return array<int, FixerInterface> */
    private static function defaultFixers(): array
    {
        return [
            new ConditionOrderFixer(),
            new PerformanceFixer(),
            new CodeStyleFixer(),
            new AutoloadFixer(),
        ];
    }

    protected function runFixers(Baseline $baseline, ToolResolver $resolver): FixResult
    {
        $result = new FixResult();

        foreach (self::defaultFixers() as $fixer) {
            $result->add($fixer->fix($baseline, $resolver));
        }

        return $result;
    }

    /**
     * @param  callable(string, Catraca): int  $operation
     */
    private function runForProject(InputInterface $input, OutputInterface $output, callable $operation): int
    {
        $projectRoot = $this->resolveProjectRoot($input, $output);
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $profile = $this->stringOption($input, 'profile') ?? 'default';
        $changedFrom = $this->stringOption($input, 'changed-from');
        $timeout = $input->getOption('timeout');

        $catraca = new Catraca(
            $projectRoot,
            profile: $profile,
            changedFrom: $changedFrom,
            timeoutOverride: is_numeric($timeout) ? (int) $timeout : null,
        );
        $this->setActiveCatraca($catraca);

        try {
            return $operation($projectRoot, $catraca);
        } finally {
            $this->setActiveCatraca(null);
        }
    }

    protected function runCatraca(
        InputInterface $input,
        OutputInterface $output,
        Catraca $catraca,
        bool $initialize = false,
    ): CheckResult {
        $this->liveCheckRendered = false;
        $renderer = $this->createLiveRenderer($input, $output, $catraca);

        if ($renderer === null) {
            return $initialize ? $catraca->init() : $catraca->check();
        }

        $this->liveCheckRendered = true;
        $renderer->start();

        return $initialize ? $catraca->init($renderer) : $catraca->check($renderer);
    }

    protected function addStandardOptions(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project root path', getcwd())
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: human, json, json-pretty, github, sarif, junit',
                'human',
            )
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write formatted output to a file')
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_REQUIRED,
                'Named configuration and baseline profile',
                'default',
            )
            ->addOption(
                'changed-from',
                null,
                InputOption::VALUE_REQUIRED,
                'Only analyze PHP files changed from this Git reference',
            )
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Override process timeout in seconds')
            ->addOption('save-run', null, InputOption::VALUE_NONE, 'Persist this run under .catraca/runs')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text output (no ANSI colors)');
    }

    private function resolveProjectRoot(InputInterface $input, OutputInterface $output): ?string
    {
        $pathOption = $input->getOption('path');
        $rawPath = is_string($pathOption) ? $pathOption : null;
        $resolver = new ProjectResolver();
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

        if ($format === 'human' && AgentDetector::isRunningInAgent()) {
            return 'json';
        }

        return $format;
    }

    private function isPlainOutput(InputInterface $input, OutputInterface $output): bool
    {
        return $input->getOption('plain') || !$output->isDecorated();
    }

    /**
     * @throws JsonException
     */
    protected function formatResult(InputInterface $input, OutputInterface $output, CheckResult $result): void
    {
        $format = $this->resolveFormat($input);
        $humanFormatter = new HumanFormatter();

        $formatted = match ($format) {
            'json' => (new JsonFormatter())->format($result),
            'json-pretty' => (new JsonFormatter())->format($result, true),
            'github' => (new GithubFormatter())->format($result),
            'sarif' => (new SarifFormatter())->format($result),
            'junit' => (new JunitFormatter())->format($result),
            'human' => $this->liveCheckRendered
                ? $humanFormatter->formatSummary($result)
                : (
                    $this->isPlainOutput($input, $output)
                        ? $humanFormatter->formatPlain($result)
                        : $humanFormatter->format($result)
                ),
            default => $humanFormatter->format($result),
        };

        $outputPath = $this->stringOption($input, 'output');
        if ($outputPath !== null) {
            file_put_contents($outputPath, $formatted, LOCK_EX);
        } else {
            $output->write($formatted);
        }
        $this->liveCheckRendered = false;
    }

    /**
     * @throws JsonException
     */
    protected function formatFixResult(InputInterface $input, OutputInterface $output, FixResult $result): void
    {
        $format = $this->resolveFormat($input);

        $formatted = match ($format) {
            'json' => (new FixJsonFormatter())->format($result),
            'json-pretty' => (new FixJsonFormatter())->format($result, true),
            'human' => $this->isPlainOutput($input, $output)
                ? (new FixHumanFormatter())->formatPlain($result)
                : (new FixHumanFormatter())->format($result),
            default => (new FixHumanFormatter())->format($result),
        };

        $output->write($formatted);
    }

    protected function saveRunIfEnabled(InputInterface $input, string $projectRoot, CheckResult $result): ?string
    {
        $profile = $this->stringOption($input, 'profile') ?? 'default';
        $baseline = new Baseline($projectRoot, profile: $profile);
        $enabled = $input->getOption('save-run') || $baseline->getConfig('history', 'enabled', false) === true;
        if (!$enabled) {
            return null;
        }

        $retention = $baseline->getConfig('history', 'retention', 50);

        return (new RunHistoryStore())->write(
            $result,
            $projectRoot,
            $profile,
            is_numeric($retention) ? (int) $retention : 50,
        );
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        if (!$input->hasOption($name)) {
            return null;
        }

        $value = $input->getOption($name);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function createLiveRenderer(
        InputInterface $input,
        OutputInterface $output,
        Catraca $catraca,
    ): ?LiveCheckRenderer {
        if (
            !$output instanceof ConsoleOutputInterface
            || $this->resolveFormat($input) !== 'human'
            || $this->isPlainOutput($input, $output)
        ) {
            return null;
        }

        return new LiveCheckRenderer($output->section(), $catraca->getGateLabels());
    }
}
