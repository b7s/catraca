<?php

declare(strict_types=1);

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\Gate\PerformanceGate;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\MagoRunner;
use B7S\Catraca\ProcessRunner;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use Throwable;

use function is_array;

readonly class PerformanceFixer implements FixerInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
        private MagoRunner $magoRunner = new MagoRunner(),
    ) {}

    public function getLabel(): string
    {
        return 'Performance';
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $tool = GateToolRegistry::resolve($baseline, $resolver, 'performance');
        if ($tool !== null && $tool->name === 'mago') {
            $mago = $tool->path;
            try {
                $result = $this->magoRunner->fixLint(
                    $mago,
                    $this->pathResolver->resolveForBaseline($baseline),
                    $baseline,
                );

                if ($result->exitCode === 0) {
                    return new FixerResult(label: 'Performance (Mago lint)', fixed: true);
                }

                return new FixerResult(
                    label: 'Performance (Mago lint)',
                    message: 'issues remain after applying safe fixes',
                );
            } catch (Throwable $exception) {
                return new FixerResult(label: 'Performance (Mago lint)', message: $exception->getMessage());
            }
        }

        $fixer = $tool !== null && $tool->name === 'php-cs-fixer' ? $tool->path : null;
        if ($fixer === null) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'skipped (install mago 1.45.0 or php-cs-fixer)',
            );
        }

        $enabledRules = $baseline->getArrayConfig('performance', 'rules', []);
        /** @var array<string, bool> $enabledRules */
        $enabledRules = array_filter($enabledRules, static fn(mixed $v): bool => is_bool($v), ARRAY_FILTER_USE_BOTH);
        $rulesJson = PerformanceGate::buildRulesJson($enabledRules);

        if ($rulesJson === '{}') {
            return new FixerResult(label: $this->getLabel(), skipped: true, message: 'no rules enabled');
        }

        $paths = $this->pathResolver->resolve($resolver->getProjectRoot(), $baseline->getSourceDirs());
        $cmd = [
            $resolver->resolvePhp(),
            $fixer,
            'fix',
            '--allow-risky=yes',
            '--using-cache=no',
            '--rules=' . $rulesJson,
        ];
        foreach ($paths as $path) {
            $cmd[] = $path;
        }

        $result = $this->runner->run($cmd);

        if ($result['success']) {
            return new FixerResult(label: 'Performance (php-cs-fixer)', fixed: true);
        }

        return new FixerResult(label: 'Performance (php-cs-fixer)', message: $result['errorOutput']);
    }
}
