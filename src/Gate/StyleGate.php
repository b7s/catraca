<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\CsFixerResultParser;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\GateToolRegistry;
use B7S\Catraca\MagoRunner;
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
        private SourcePathResolver $pathResolver = new SourcePathResolver(),
        private MagoRunner $magoRunner = new MagoRunner(),
    ) {}

    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $tool = GateToolRegistry::resolve($baseline, $resolver, 'style');
        if ($tool !== null) {
            return match ($tool->name) {
                'mago' => $this->runMago($tool->path, $baseline),
                'pint' => $this->runPint($tool->path, $baseline, $resolver),
                default => $this->runCsFixer($tool->path, $baseline, $resolver),
            };
        }

        return new GateResult(
            status: Status::Skip,
            name: 'style',
            label: 'Code Style',
            message: 'No code style tool found (install mago, pint, or php-cs-fixer)',
            severity: Severity::Warn,
        );
    }

    private function runMago(string $mago, Baseline $baseline): GateResult
    {
        $result = $this->magoRunner->format($mago, $this->pathResolver->resolveForBaseline($baseline), $baseline, true);
        $violations = 0;
        if ($result->exitCode !== 0) {
            $violations = preg_match('/Found (\d+) file/', $result->errorOutput, $matches) === 1
                ? (int) $matches[1]
                : 1;
        }
        $files = $violations === 0 ? [] : ['Run `mago format` to format changed files'];

        return $this->buildStyleResult($violations, $files, $baseline, ['tool' => 'Mago 1.45.0'], 'Mago');
    }

    private function runPint(string $pint, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $process = new Process([$resolver->resolvePhp(), $pint, '--test'], timeout: $baseline->getGateTimeout('style'));
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
                        if (!is_array($error)) {
                            continue;
                        }
                        $fileName = is_string($error['file'] ?? null)
                            ? $error['file']
                            : (is_string($error['path'] ?? null) ? $error['path'] : 'unknown');
                        $line = is_int($error['line'] ?? null) ? $error['line'] : 0;
                        $files[] = $fileName . ':' . $line;
                    }
                }
            }
        }

        if ($violations === 0 && $exitCode !== 0) {
            $lines = array_filter(explode("\n", $output));
            $dirtyFiles = array_filter(
                $lines,
                static fn(string $l): bool => !str_contains($l, ' ') && preg_match('/\.(php|blade\.php)$/', $l),
            );
            if (count($dirtyFiles) > 0) {
                $violations = count($dirtyFiles);
                $files = array_values(array_map(static fn(string $f): string => trim($f), $dirtyFiles));
            } else {
                $violations = 1;
                $files[] = 'Run `pint --test` for details or fix with `pint`';
            }
        }

        return $this->buildStyleResult(
            $violations,
            $files,
            $baseline,
            $violations > 0 ? ['files' => $files] : null,
            'Pint',
        );
    }

    private function runCsFixer(string $fixer, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $paths = $this->pathResolver->resolveForBaseline($baseline);
        $cmd = [$resolver->resolvePhp(), $fixer, 'fix', '--dry-run', '--diff', '--format=json'];
        foreach ($paths as $path) {
            $cmd[] = $path;
        }

        $process = new Process($cmd, timeout: $baseline->getGateTimeout('style'));
        $process->run();

        $result = CsFixerResultParser::parseJsonOutput($process->getOutput());

        if ($result['violations'] === 0 && $process->getExitCode() !== 0) {
            $result['violations'] =
                substr_count($process->getOutput(), '1)') + substr_count($process->getOutput(), '2)');
        }

        return $this->buildStyleResult($result['violations'], $result['files'], $baseline, toolName: 'PHP CS Fixer');
    }

    /**
     * @param  array<int, string>  $files
     * @param  array<string, mixed>|null  $details
     */
    private function buildStyleResult(
        int $violations,
        array $files,
        Baseline $baseline,
        ?array $details = null,
        string $toolName = 'unknown',
    ): GateResult {
        $baselineViolations = $baseline->getResult('style', 'violations', 0);
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
            message: sprintf(
                '%d violations (baseline: %d) via %s',
                $violations,
                is_int($baselineViolations) ? $baselineViolations : 0,
                $toolName,
            ),
            severity: Severity::Block,
            baseline: ['violations' => $baselineViolations],
            current: ['violations' => $violations],
            actions: $actions,
            details: $details,
        );
    }
}
