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
        $skipped = 0;

        $result = $this->fixCodeStyle($resolver, $io);
        $fixed += $result['fixed'];
        $skipped += $result['skipped'];

        $result = $this->fixPerformance($resolver, $io);
        $fixed += $result['fixed'];
        $skipped += $result['skipped'];

        $result = $this->fixAutoload($resolver, $io);
        $fixed += $result['fixed'];
        $skipped += $result['skipped'];

        if ($fixed > 0) {
            $io->success(sprintf('Fixed %d issue(s).', $fixed));
        }
        if ($skipped > 0) {
            $io->note(sprintf('%d fixer(s) skipped (tool not installed).', $skipped));
        }
        if ($fixed === 0 && $skipped === 0) {
            $io->info('Nothing to fix.');
        }

        return Command::SUCCESS;
    }

    private function fixCodeStyle(ToolResolver $resolver, SymfonyStyle $io): array
    {
        $pint = $resolver->resolve('pint');
        if ($pint !== null) {
            return $this->runFix('Code Style (pint)', [$resolver->resolvePhp(), $pint], $io);
        }

        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer !== null) {
            return $this->runFix('Code Style (php-cs-fixer)', [$resolver->resolvePhp(), $fixer, 'fix'], $io);
        }

        $io->text('  — Code Style: skipped (install pint or php-cs-fixer)');

        return ['fixed' => 0, 'skipped' => 1];
    }

    private function fixPerformance(ToolResolver $resolver, SymfonyStyle $io): array
    {
        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer === null) {
            $io->text('  — Performance: skipped (install php-cs-fixer)');

            return ['fixed' => 0, 'skipped' => 1];
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

    private function fixAutoload(ToolResolver $resolver, SymfonyStyle $io): array
    {
        $composer = $resolver->resolve('composer');
        if ($composer === null) {
            $io->text('  — Autoload: skipped (composer not found)');

            return ['fixed' => 0, 'skipped' => 1];
        }

        $autoloadFile = $resolver->getProjectRoot().'/vendor/composer/autoload_classmap.php';
        if (file_exists($autoloadFile)) {
            return ['fixed' => 0, 'skipped' => 0];
        }

        return $this->runFix(
            'Autoload optimization',
            [$resolver->resolvePhp(), $composer, 'dump-autoload', '-o'],
            $io,
        );
    }

    private function runFix(string $label, array $command, SymfonyStyle $io): array
    {
        $io->text(sprintf('  <info>%s</info>...', $label));

        $process = new Process($command);
        $process->run();

        if ($process->isSuccessful()) {
            $io->text(sprintf('  ✔ %s — done', $label));

            return ['fixed' => 1, 'skipped' => 0];
        }

        $io->text(sprintf('  ✘ %s — %s', $label, trim($process->getErrorOutput() ?: $process->getOutput())));

        return ['fixed' => 0, 'skipped' => 0];
    }
}
