<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\Baseline;
use B7S\Catraca\Catraca;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\MagoVersionChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

#[AsCommand(name: 'config:validate', description: 'Validate the Catraca baseline and configuration schema')]
final class ConfigValidateCommand extends ProjectCommand
{
    protected function configure(): void
    {
        $this->addStandardOptions();
    }

    protected function executeForProject(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        Catraca $catraca,
    ): int {
        try {
            $baseline = new Baseline($projectRoot, profile: $this->stringOption($input, 'profile') ?? 'default');
            $data = $baseline->read();
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($data === null || !is_array($data['config'] ?? null) || !is_array($data['results'] ?? null)) {
            $output->writeln('<error>Baseline must contain config and results objects.</error>');

            return Command::FAILURE;
        }

        foreach (['missing_tool', 'unavailable_metric', 'internal_error'] as $key) {
            $default = $key === 'missing_tool' ? 'skip' : ($key === 'unavailable_metric' ? 'warn' : 'fail');
            if (!in_array($baseline->getPolicy($key, $default), ['fail', 'warn', 'skip'], true)) {
                $output->writeln('<error>Invalid policy ' . $key . ': expected fail, warn, or skip.</error>');

                return Command::FAILURE;
            }
        }

        try {
            foreach (GateToolRegistry::gates() as $gate) {
                GateToolRegistry::candidates($baseline, $gate);
            }
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $tools = is_array($data['config']['tools'] ?? null) ? $data['config']['tools'] : [];
        $options = is_array($tools['options'] ?? null) ? $tools['options'] : [];
        $magoOptions = is_array($options['mago'] ?? null) ? $options['mago'] : [];

        /** @var mixed $magoLevelRaw */
        $magoLevelRaw = $magoOptions['minimum_report_level'] ?? 'error';
        $magoLevel = is_string($magoLevelRaw) ? $magoLevelRaw : 'error';
        if (!in_array($magoLevel, ['help', 'note', 'warning', 'error'], true)) {
            $output->writeln(
                '<error>Invalid mago minimum_report_level: expected help, note, warning, or error.</error>',
            );

            return Command::FAILURE;
        }

        /** @var mixed $minimumMagoVersionRaw */
        $minimumMagoVersionRaw = $magoOptions['minimum_version'] ?? GateToolRegistry::MINIMUM_MAGO_VERSION;
        $minimumMagoVersion = is_string($minimumMagoVersionRaw)
            ? $minimumMagoVersionRaw
            : GateToolRegistry::MINIMUM_MAGO_VERSION;
        if (!is_string($minimumMagoVersion) || !MagoVersionChecker::isValid($minimumMagoVersion)) {
            $output->writeln(
                '<error>Invalid mago minimum_version: expected a semantic version such as 1.45.0.</error>',
            );

            return Command::FAILURE;
        }

        /** @var mixed $magoThreadsRaw */
        $magoThreadsRaw = $magoOptions['threads'] ?? 0;
        $magoThreads = is_int($magoThreadsRaw) ? $magoThreadsRaw : 0;
        if (!is_int($magoThreads) || $magoThreads < 0 || $magoThreads > 128) {
            $output->writeln('<error>Invalid mago threads: expected an integer from 0 (automatic) to 128.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Configuration is valid (' . Baseline::SCHEMA . ').</info>');

        return Command::SUCCESS;
    }
}
