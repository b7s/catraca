<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use B7S\Catraca\Baseline;
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
use function is_int;
use function sprintf;
use function strlen;

class DuplicationGate implements GateInterface
{
    private const float DUPLICATION_PERCENT = 0.0;

    private const int DUPLICATION_MIN_LINE = 0;

    private const int DUPLICATION_MIN_TOKENS = 30;

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
        $paths = (new SourcePathResolver())->resolveForBaseline($baseline);

        $command = [
            $resolver->resolvePhp(),
            $phpcpd,
            '--fuzzy',
            '--verbose',
            '--min-lines',
            (string) $this->getBaselineMinLines($baseline),
            '--min-tokens',
            (string) $this->getBaselineMinTokens($baseline),
            ...$paths,
        ];

        $process = new Process($command, timeout: $baseline->getGateTimeout('duplication'));
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

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
            PREG_SET_ORDER,
        );

        $root = rtrim($baseline->projectRoot, '/') . '/';

        foreach ($matches as $m) {
            $fileA = $this->relativePath($m[1], $root);
            $fileB = $this->relativePath($m[4], $root);
            $startA = (int) $m[2];
            $endA = (int) $m[3];
            $startB = (int) $m[5];
            $endB = (int) $m[6];
            $lineCount = $endA - $startA + 1;

            $cloneDetails[] = [
                'file_a' => $fileA . ':' . $startA . '-' . $endA,
                'file_b' => $fileB . ':' . $startB . '-' . $endB,
                'lines' => $lineCount,
            ];
            $clones[] = sprintf('%s:%d-%d <-> %s:%d-%d', $fileA, $startA, $endA, $fileB, $startB, $endB);
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
                    $percentage,
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
        if ($baseline->getStringConfig('duplication', 'mode', 'no_regression') === 'absolute') {
            $maximum = $baseline->getFloatConfig('duplication', 'max_percentage', 0.0);

            return is_numeric($maximum) ? (float) $maximum : 0.0;
        }
        $val = $baseline->getFloatResult('duplication', 'percentage', self::DUPLICATION_PERCENT);

        return is_numeric($val) ? (float) $val : self::DUPLICATION_PERCENT;
    }

    private function getBaselineMinLines(Baseline $baseline): int
    {
        $val = $baseline->getIntConfig('duplication', 'min_lines', self::DUPLICATION_MIN_LINE);

        return is_int($val) ? $val : self::DUPLICATION_MIN_LINE;
    }

    private function getBaselineMinTokens(Baseline $baseline): int
    {
        $val = $baseline->getIntConfig('duplication', 'min_tokens', self::DUPLICATION_MIN_TOKENS);

        return is_int($val) ? $val : self::DUPLICATION_MIN_TOKENS;
    }

    private function relativePath(string $path, string $root): string
    {
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }
}
