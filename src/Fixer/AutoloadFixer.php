<?php

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\ProcessRunner;
use B7S\Catraca\ToolResolver;

readonly class AutoloadFixer implements FixerInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner,
    ) {}

    public function getLabel(): string
    {
        return 'Autoload optimization';
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $composer = $resolver->resolve('composer');
        if ($composer === null) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'skipped (composer not found)',
            );
        }

        $autoloadFile = $resolver->getProjectRoot().'/vendor/composer/autoload_classmap.php';
        if (file_exists($autoloadFile)) {
            return new FixerResult(
                label: $this->getLabel(),
                skipped: true,
                message: 'already optimized',
            );
        }

        $result = $this->runner->run([
            $resolver->resolvePhp(), $composer, 'dump-autoload', '-o',
        ]);

        if ($result['success']) {
            return new FixerResult(label: $this->getLabel(), fixed: true);
        }

        return new FixerResult(
            label: $this->getLabel(),
            message: $result['errorOutput'],
        );
    }
}
