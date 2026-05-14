<?php

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\Gate\PerformanceGate;
use B7S\Catraca\ProcessRunner;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;

readonly class PerformanceFixer implements FixerInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner,
        private SourcePathResolver $pathResolver = new SourcePathResolver,
    ) {}

    public function getLabel(): string
    {
        return 'Performance';
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer === null) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'skipped (install php-cs-fixer)',
            );
        }

        $enabledRules = $baseline->get('performance', 'rules', []);
        $rulesJson = PerformanceGate::buildRulesJson($enabledRules);

        if ($rulesJson === '{}') {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'no rules enabled',
            );
        }

        $paths = $this->pathResolver->resolve($resolver->getProjectRoot(), $baseline->getSourceDirs());
        $cmd = [
            $resolver->resolvePhp(), $fixer, 'fix',
            '--allow-risky=yes',
            '--using-cache=no',
            '--rules='.$rulesJson,
        ];
        foreach ($paths as $path) {
            $cmd[] = $path;
        }

        $result = $this->runner->run($cmd);

        if ($result['success']) {
            return new FixerResult(label: 'Performance (php-cs-fixer)', fixed: true);
        }

        return new FixerResult(
            label: 'Performance (php-cs-fixer)',
            message: $result['errorOutput'],
        );
    }
}
