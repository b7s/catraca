<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\Baseline;
use B7S\Catraca\Catraca;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\MagoVersionChecker;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use Parallite\ForkExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function extension_loaded;
use function implode;

#[AsCommand(name: 'doctor', description: 'Diagnose tools, runtime capabilities, and effective configuration')]
final class DoctorCommand extends ProjectCommand
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
        $baseline = new Baseline($projectRoot, profile: $this->stringOption($input, 'profile') ?? 'default');
        $resolver = new ToolResolver($projectRoot);
        $magoPath = $resolver->resolve('mago');
        $magoVersion = $magoPath === null ? null : (new MagoVersionChecker())->detect($magoPath, $projectRoot);
        $rows = [
            ['PHP', PHP_VERSION, 'available'],
            [
                'Parallel workers',
                (string) $baseline->getMaxProcesses(),
                ForkExecutor::isAvailable() ? 'available' : 'sequential fallback',
            ],
            [
                'Mago minimum',
                $baseline->getMagoMinimumVersion(),
                'preferred v2 backend',
            ],
            [
                'Mago installed',
                $magoVersion ?? 'not detected',
                $magoVersion !== null && MagoVersionChecker::satisfies($magoVersion, $baseline->getMagoMinimumVersion())
                    ? 'compatible'
                    : 'missing or too old',
            ],
            ['Mago threads/process', (string) $baseline->getMagoThreads(), 'shared CPU budget'],
            [
                'pcntl',
                extension_loaded('pcntl') ? 'yes' : 'no',
                extension_loaded('pcntl') ? 'available' : 'sequential fallback',
            ],
            [
                'Coverage driver',
                extension_loaded('pcov') ? 'pcov' : (extension_loaded('xdebug') ? 'xdebug' : 'none'),
                extension_loaded('pcov') || extension_loaded('xdebug') ? 'available' : 'coverage unavailable',
            ],
            ['Source scope', implode(', ', (new SourcePathResolver())->resolveForBaseline($baseline)), 'resolved'],
        ];

        foreach (GateToolRegistry::gates() as $gate) {
            $rows[] = [$gate . ' tool', $baseline->getGateTool($gate), GateToolRegistry::description($gate)];
        }

        foreach ([
            'composer',
            'mago',
            'pint',
            'php-cs-fixer',
            'phpstan',
            'psalm',
            'phpunit',
            'pest',
            'phpcpd',
            'phpmetrics',
        ] as $tool) {
            $path = $resolver->resolve($tool);
            $rows[] = [$tool, $path ?? 'not found', $path === null ? 'optional/missing' : 'available'];
        }

        (new Table($output))
            ->setHeaders(['Capability', 'Value', 'Status'])
            ->setRows($rows)
            ->render();

        return Command::SUCCESS;
    }
}
