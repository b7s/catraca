<?php

declare(strict_types=1);

namespace B7S\Catraca;

use B7S\Catraca\Enum\FailurePolicy;
use B7S\Catraca\Enum\PolicyMode;
use B7S\Catraca\Enum\Status;

use function is_numeric;
use function is_string;

final readonly class GatePolicyEvaluator
{
    /** @var array<string, array{metric: string, higher_is_better: bool}> */
    private const array METRICS = [
        'security' => ['metric' => 'findings', 'higher_is_better' => false],
        'style' => ['metric' => 'violations', 'higher_is_better' => false],
        'static_analysis' => ['metric' => 'errors', 'higher_is_better' => false],
        'coverage' => ['metric' => 'percentage', 'higher_is_better' => true],
        'duplication' => ['metric' => 'percentage', 'higher_is_better' => false],
        'file_size' => ['metric' => 'over_limit', 'higher_is_better' => false],
        'complexity' => ['metric' => 'max_ccn', 'higher_is_better' => false],
        'performance' => ['metric' => 'violations', 'higher_is_better' => false],
    ];

    public function evaluate(GateResult $result, Baseline $baseline): GateResult
    {
        if ($result->status === Status::Cancelled) {
            return $result;
        }

        if ($result->status === Status::Skip) {
            return $this->withStatus(
                $result,
                $this->failureStatus($baseline->getPolicy('missing_tool', FailurePolicy::Skip->value)),
            );
        }

        $metric = self::METRICS[$result->name] ?? null;
        if ($metric === null) {
            return $result;
        }

        $current = $result->current[$metric['metric']] ?? null;
        if ($current === null) {
            return $this->withStatus(
                $result,
                $this->failureStatus($baseline->getPolicy('unavailable_metric', FailurePolicy::Warn->value)),
            );
        }

        $modeValue = $baseline->getConfig($result->name, 'mode', PolicyMode::NoRegression->value);
        $mode = is_string($modeValue) ? PolicyMode::tryFrom($modeValue) : null;
        $mode ??= PolicyMode::NoRegression;

        if ($mode === PolicyMode::Absolute) {
            return $result;
        }

        if ($mode === PolicyMode::Informational) {
            return $this->withStatus($result, $result->isFail() ? Status::Warn : Status::Pass);
        }

        $baselineValue = $result->baseline[$metric['metric']] ?? null;
        if (!is_numeric($baselineValue) || !is_numeric($current)) {
            return $this->withStatus(
                $result,
                $this->failureStatus($baseline->getPolicy('unavailable_metric', FailurePolicy::Warn->value)),
            );
        }

        $regressed = $metric['higher_is_better']
            ? (float) $current < (float) $baselineValue
            : (float) $current > (float) $baselineValue;

        return $this->withStatus($result, $regressed ? Status::Fail : Status::Pass);
    }

    private function failureStatus(mixed $policy): Status
    {
        $resolved = is_string($policy) ? FailurePolicy::tryFrom($policy) : null;

        return match ($resolved ?? FailurePolicy::Warn) {
            FailurePolicy::Fail => Status::Fail,
            FailurePolicy::Warn => Status::Warn,
            FailurePolicy::Skip => Status::Skip,
        };
    }

    private function withStatus(GateResult $result, Status $status): GateResult
    {
        if ($status === $result->status) {
            return $result;
        }

        return new GateResult(
            status: $status,
            name: $result->name,
            label: $result->label,
            message: $result->message,
            severity: $result->severity,
            baseline: $result->baseline,
            current: $result->current,
            actions: $status === Status::Fail ? $result->actions : null,
            details: $result->details,
        );
    }
}
