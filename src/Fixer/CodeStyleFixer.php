<?php

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\ProcessRunner;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;

readonly class CodeStyleFixer implements FixerInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner,
        private SourcePathResolver $pathResolver = new SourcePathResolver,
    ) {}

    public function getLabel(): string
    {
        return 'Code Style';
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $pint = $resolver->resolve('pint');
        if ($pint !== null) {
            $result = $this->runner->run([
                $resolver->resolvePhp(), $pint, '--parallel',
            ]);

            return $this->buildResult('Code Style (pint)', $result);
        }

        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer !== null) {
            $paths = $this->pathResolver->resolve($resolver->getProjectRoot(), $baseline->getSourceDirs());
            $cmd = [$resolver->resolvePhp(), $fixer, 'fix'];
            foreach ($paths as $path) {
                $cmd[] = $path;
            }

            return $this->buildResult('Code Style (php-cs-fixer)', $this->runner->run($cmd));
        }

        return new FixerResult(
            label: $this->getLabel(),
            skipped: true,
            message: 'skipped (install pint or php-cs-fixer)',
        );
    }

    /**
     * @param  array{success: bool, output: string, errorOutput: string}  $result
     */
    private function buildResult(string $label, array $result): FixerResult
    {
        if ($result['success']) {
            return new FixerResult(label: $label, fixed: true);
        }

        return new FixerResult(
            label: $label,
            message: $result['errorOutput'],
        );
    }
}
