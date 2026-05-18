<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\CsFixerResultParser;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\SourcePathResolver;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

use function array_slice;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

readonly class StyleGate implements GateInterface
{
    public function __construct(
        private SourcePathResolver $pathResolver = new SourcePathResolver,
    ) {}

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $pint = $resolver->resolve('pint');
        if ($pint !== null) {
            return $this->runPint($pint, $baseline, $resolver);
        }

        $fixer = $resolver->resolve('php-cs-fixer');
        if ($fixer !== null) {
            return $this->runCsFixer($fixer, $baseline, $resolver);
        }

        return new GateResult(
            status: Status::Skip,
            name: 'style',
            label: 'Code Style',
            message: 'No code style tool found (install pint or php-cs-fixer)',
            severity: Severity::Warn,
        );
    }

    private function runPint(string $pint, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $process = new Process([$resolver->resolvePhp(), $pint, '--test']);
        $process->run();

        $output = $process->getOutput();
        $exitCode = $process->getExitCode();

        $violations = 0;
        $files = [];

        if (str_contains($output, '{') && str_contains($output, '}')) {
            $data = json_decode($output, true);
            if (is_array($data)) {
                $errors = $data['errors'] ?? [];
                if (is_array($errors)) {
                    $violations = count($errors);
                    foreach ($errors as $error) {
                        if (! is_array($error)) {
                            continue;
                        }
                        $fileName = is_string($error['file'] ?? null) ? $error['file'] : (is_string($error['path'] ?? null) ? $error['path'] : 'unknown');
                        $line = is_int($error['line'] ?? null) ? $error['line'] : 0;
                        $files[] = $fileName.':'.$line;
                    }
                }
            }
        }

        if ($violations === 0 && $exitCode !== 0) {
            $lines = array_filter(explode("\n", $output));
            $dirtyFiles = array_filter($lines, static fn (string $l): bool => ! str_contains($l, ' ') && preg_match('/\.(php|blade\.php)$/', $l));
            if (count($dirtyFiles) > 0) {
                $violations = count($dirtyFiles);
                $files = array_values(array_map(static fn (string $f): string => trim($f), $dirtyFiles));
            } else {
                $violations = 1;
                $files[] = 'Run `pint --test` for details or fix with `pint`';
            }
        }

        return $this->buildStyleResult($violations, $files, $baseline, $violations > 0 ? ['files' => $files] : null);
    }

    private function runCsFixer(string $fixer, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $paths = $this->pathResolver->resolve($resolver->getProjectRoot(), $baseline->getSourceDirs());
        $cmd = [$resolver->resolvePhp(), $fixer, 'fix', '--dry-run', '--diff', '--format=json'];
        foreach ($paths as $path) {
            $cmd[] = $path;
        }

        $process = new Process($cmd);
        $process->run();

        $result = CsFixerResultParser::parseJsonOutput($process->getOutput());

        if ($result['violations'] === 0 && $process->getExitCode() !== 0) {
            $result['violations'] = substr_count($process->getOutput(), '1)') + substr_count($process->getOutput(), '2)');
        }

        return $this->buildStyleResult($result['violations'], $result['files'], $baseline);
    }

    private function buildStyleResult(int $violations, array $files, Baseline $baseline, ?array $details = null): GateResult
    {
        $baselineViolations = $baseline->get('style', 'violations', 0);
        $status = Status::Pass;
        $actions = null;

        if ($violations > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::FixStyle,
                'message' => sprintf('Fix %d code style violations', $violations),
                'files' => array_slice($files, 0, 50),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'style',
            label: 'Code Style',
            message: sprintf('%d violations (baseline: %d)', $violations, is_int($baselineViolations) ? $baselineViolations : 0),
            severity: Severity::Block,
            baseline: ['violations' => $baselineViolations],
            current: ['violations' => $violations],
            actions: $actions,
            details: $details,
        );
    }
}
