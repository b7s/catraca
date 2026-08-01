<?php

declare(strict_types=1);

namespace B7S\Catraca\Fixer;

use B7S\Catraca\Baseline;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\MagoRunner;
use B7S\Catraca\ProcessRunner;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use Throwable;

readonly class CodeStyleFixer implements FixerInterface
{
    public function __construct(
        private ProcessRunner $runner = new ProcessRunner(),
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
        private MagoRunner $magoRunner = new MagoRunner(),
    ) {}

    public function getLabel(): string
    {
        return 'Code Style';
    }

    public function fix(Baseline $baseline, ToolResolver $resolver): FixerResult
    {
        $tool = GateToolRegistry::resolve($baseline, $resolver, 'style');
        if ($tool?->name === 'mago') {
            $mago = $tool->path;
            try {
                $this->magoRunner->format($mago, $this->pathResolver->resolveForBaseline($baseline), $baseline, false);

                return new FixerResult(label: 'Code Style (Mago)', fixed: true);
            } catch (Throwable $exception) {
                return new FixerResult(label: 'Code Style (Mago)', message: $exception->getMessage());
            }
        }

        $pint = $tool?->name === 'pint' ? $tool->path : null;
        if ($pint !== null) {
            $result = $this->runner->run([
                $resolver->resolvePhp(),
                $pint,
                '--parallel',
            ]);

            return $this->buildResult('Code Style (pint)', $result);
        }

        $fixer = $tool?->name === 'php-cs-fixer' ? $tool->path : null;
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
            message: 'skipped (install mago 1.45.0, pint, or php-cs-fixer)',
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

        return new FixerResult(label: $label, message: $result['errorOutput']);
    }
}
