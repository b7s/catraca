<?php

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
use B7S\Catraca\Enum\ActionType;
use B7S\Catraca\Enum\Severity;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateInterface;
use B7S\Catraca\GateResult;
use B7S\Catraca\ToolResolver;
use Symfony\Component\Process\Process;

use function array_slice;
use function count;
use function is_int;
use function sprintf;
use function strlen;

class DuplicationGate implements GateInterface
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $phpcpd = $resolver->resolve('phpcpd');
        if ($phpcpd !== null) {
            return $this->runPhpcpd($phpcpd, $baseline, $resolver);
        }

        return new GateResult(
            status: Status::Skip,
            name: 'duplication',
            label: 'Duplication',
            message: 'No duplication tool found (install systemsdk/phpcpd)',
            severity: Severity::Warn,
        );
    }

    private function runPhpcpd(string $phpcpd, Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $paths = array_filter([
            $baseline->projectRoot.'/src',
            $baseline->projectRoot.'/app',
        ], 'is_dir');

        $command = [
            $resolver->resolvePhp(), $phpcpd,
            '--fuzzy',
            '--verbose',
            '--min-lines', (string) $this->getBaselineMinLines($baseline),
            '--min-tokens', (string) $this->getBaselineMinTokens($baseline),
            ...$paths,
        ];

        $process = new Process($command);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        return $this->parseResult($output, $baseline);
    }

    private function parseResult(string $output, Baseline $baseline): GateResult
    {
        $percentage = 0.0;
        if (preg_match('/([\d.]+)%\s+duplicated lines/', $output, $m)) {
            $percentage = (float) $m[1];
        }

        $clones = [];
        $cloneDetails = [];

        preg_match_all(
            '/-\s+(\S+):(\d+)-(\d+)\s+\(\d+\s+lines?\)\s*\n\s+(\S+):(\d+)-(\d+)/',
            $output,
            $matches,
            PREG_SET_ORDER
        );

        $root = rtrim($baseline->projectRoot, '/').'/';

        foreach ($matches as $m) {
            $fileA = $this->relativePath($m[1], $root);
            $fileB = $this->relativePath($m[4], $root);
            $lineCount = (int) ($m[3] - $m[2] + 1);

            $cloneDetails[] = [
                'file_a' => $fileA.':'.$m[2].'-'.$m[3],
                'file_b' => $fileB.':'.$m[5].'-'.$m[6],
                'lines' => $lineCount,
            ];
            $clones[] = sprintf(
                '%s:%s-%s <-> %s:%s-%s',
                $fileA, $m[2], $m[3], $fileB, $m[5], $m[6]
            );
        }

        $cloneCount = count($clones);
        $baselineDup = $this->getBaselineDup($baseline);

        $status = Status::Pass;
        $actions = null;

        if ($percentage > $baselineDup) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::RefactorDup,
                'message' => sprintf(
                    'Duplication increased from %.2f%% to %.2f%% — refactor duplicated code',
                    $baselineDup,
                    $percentage
                ),
                'files' => array_slice($clones, 0, 20),
            ]];
        }

        return new GateResult(
            status: $status,
            name: 'duplication',
            label: 'Duplication',
            message: sprintf('%.2f%% (baseline: %.2f%%, %d clones)', $percentage, $baselineDup, $cloneCount),
            severity: Severity::Block,
            baseline: ['percentage' => $baselineDup],
            current: ['percentage' => $percentage, 'clones' => $cloneCount],
            actions: $actions,
            details: $cloneCount > 0 ? ['clones' => $cloneDetails] : null,
        );
    }

    private function getBaselineDup(Baseline $baseline): float
    {
        $val = $baseline->get('duplication', 'percentage', 2.0);

        return is_numeric($val) ? (float) $val : 2.0;
    }

    private function getBaselineMinLines(Baseline $baseline): int
    {
        $val = $baseline->get('duplication', 'min_lines', 3);

        return is_int($val) ? $val : 3;
    }

    private function getBaselineMinTokens(Baseline $baseline): int
    {
        $val = $baseline->get('duplication', 'min_tokens', 30);

        return is_int($val) ? $val : 30;
    }

    private function relativePath(string $path, string $root): string
    {
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }
}
