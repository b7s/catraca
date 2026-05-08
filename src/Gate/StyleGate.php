<?php

namespace B7S\RatchetBabysit\Gate;

use B7S\RatchetBabysit\Baseline;
use B7S\RatchetBabysit\Enum\ActionType;
use B7S\RatchetBabysit\Enum\Severity;
use B7S\RatchetBabysit\Enum\Status;
use B7S\RatchetBabysit\GateResult;
use B7S\RatchetBabysit\ToolResolver;
use Symfony\Component\Process\Process;

class StyleGate
{
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
                $violations = is_countable($errors) ? count($errors) : 0;
                foreach ($errors as $error) {
                    $files[] = ($error['file'] ?? $error['path'] ?? 'unknown')
                        . ':' . ($error['line'] ?? 0);
                }
            }
        }

        if ($violations === 0 && $exitCode !== 0) {
            $lines = array_filter(explode("\n", $output));
            $dirtyFiles = array_filter($lines, fn($l) => preg_match('/\.(php|blade\.php)$/', $l) && !str_contains($l, ' '));
            if (count($dirtyFiles) > 0) {
                $violations = count($dirtyFiles);
                $files = array_values($dirtyFiles);
            } else {
                $violations = 1;
                $files[] = 'Run `pint --test` for details';
            }
        }

        $baselineViolations = $baseline->get('style', 'violations', 0);

        $status = Status::Pass;
        $actions = null;

        if ($violations > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::FixStyle,
                'message' => sprintf('Fix %d code style violations', $violations),
                'files' => $files,
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'style',
            label: 'Code Style',
            message: sprintf('%d violations (baseline: %d)', $violations, $baselineViolations),
            severity: Severity::Block,
            baseline: ['violations' => $baselineViolations],
            current: ['violations' => $violations],
            actions: $actions,
            details: $violations > 0 ? ['files' => $files] : null,
        );
    }

    private function runCsFixer(string $fixer, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $process = new Process([$resolver->resolvePhp(), $fixer, 'fix', '--dry-run', '--diff', '--format=json']);
        $process->run();

        $output = $process->getOutput();
        $data = json_decode($output, true);

        $violations = 0;
        $files = [];

        if (is_array($data)) {
            $files = $data['files'] ?? [];
            foreach ($data as $key => $value) {
                if (isset($value['file'])) {
                    $files[] = $value['file'];
                }
            }
            $violations = count($files);
        } elseif ($process->getExitCode() !== 0) {
            $violations = substr_count($process->getOutput(), '1)') + substr_count($process->getOutput(), '2)');
        }

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
            message: sprintf('%d violations (baseline: %d)', $violations, $baselineViolations),
            severity: Severity::Block,
            baseline: ['violations' => $baselineViolations],
            current: ['violations' => $violations],
            actions: $actions,
        );
    }
}
