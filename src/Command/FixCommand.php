<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\ToolResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'fix',
    description: 'Auto-fix code style and performance issues',
)]
class FixCommand extends Command
{
    use CommandHelper;

    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Project root path', getcwd())
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain text output (no ANSI colors)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($input, $output);
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $resolver = new ToolResolver($projectRoot);
        $fixed = 0;

        $fixed += $this->fixCodeStyle($resolver, $io);
        $fixed += $this->fixPerformance($resolver, $io);
        $fixed += $this->fixAutoload($resolver, $io);

        if ($fixed > 0) {
            $io->success(sprintf('Fixed %d issue(s).', $fixed));
        } else {
            $io->info('Nothing to fix.');
        }

        return Command::SUCCESS;
    }

    private function fixCodeStyle(ToolResolver $resolver, SymfonyStyle $io): int
    {
        $pint = $resolver->resolve('pint');
        if ($pint !== null) {
            return $this->runFix('Code Style (pint)', [$resolver->resolvePhp(), $pint], $io);
        }

        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer !== null) {
            return $this->runFix('Code Style (php-cs-fixer)', [$resolver->resolvePhp(), $fixer, 'fix'], $io);
        }

        return 0;
    }

    private function fixPerformance(ToolResolver $resolver, SymfonyStyle $io): int
    {
        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer === null) {
            return 0;
        }

        $rules = implode(',', [
            'global_namespace_import',
            'no_unused_imports',
            'fully_qualified_strict_types',
            'lambda_not_used_import',
        ]);

        return $this->runFix(
            'Performance (php-cs-fixer)',
            [$resolver->resolvePhp(), $fixer, 'fix', '--rules='.$rules],
            $io,
        );
    }

    private function fixAutoload(ToolResolver $resolver, SymfonyStyle $io): int
    {
        $composer = $resolver->resolve('composer');
        if ($composer === null) {
            return 0;
        }

        $autoloadFile = $resolver->getProjectRoot().'/vendor/composer/autoload_classmap.php';
        if (file_exists($autoloadFile)) {
            return 0;
        }

        return $this->runFix(
            'Autoload optimization',
            [$resolver->resolvePhp(), $composer, 'dump-autoload', '-o'],
            $io,
        );
    }

    private function runFix(string $label, array $command, SymfonyStyle $io): int
    {
        $io->text(sprintf('  <info>%s</info>...', $label));

        $process = new Process($command);
        $process->run();

        if ($process->isSuccessful()) {
            $io->text(sprintf('  ✔ %s — done', $label));

            return 1;
        }

        $io->text(sprintf('  ✘ %s — %s', $label, trim($process->getErrorOutput() ?: $process->getOutput())));

        return 0;
    }
}
