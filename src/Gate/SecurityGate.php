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

class SecurityGate implements GateInterface
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $composer = $resolver->resolve('composer') ?? 'composer';

        $process = new Process([$composer, 'audit', '--format=json']);
        $process->setWorkingDirectory($baseline->projectRoot);
        $process->run();

        $output = $process->getOutput();
        $data = json_decode($output, true);
        if (! is_array($data)) {
            return new GateResult(
                status: Status::Skip,
                name: 'security',
                label: 'Security Audit',
                message: 'Could not parse composer audit output',
                severity: Severity::Warn,
            );
        }

        $rawAdvisories = $data['advisories'] ?? [];
        $advisories = is_array($rawAdvisories) ? $rawAdvisories : [];

        $critical = array_filter($advisories, static function (mixed $a): bool {
            if (! is_array($a)) {
                return false;
            }
            $severity = $a['severity'] ?? '';

            return in_array($severity, ['critical', 'high'], true);
        });

        $criticalCount = count($critical);
        $totalCount = count($advisories);

        $baselineAdvisoryCount = $baseline->get('security', 'advisories', 0);

        $actions = null;
        $details = null;
        $status = Status::Pass;

        if ($criticalCount > 0) {
            $status = Status::Fail;
            $files = [];
            /** @var array<int, array<string, mixed>> $detailList */
            $detailList = [];
            foreach ($critical as $a) {
                if (! is_array($a)) {
                    continue;
                }
                $title = is_string($a['title'] ?? null) ? $a['title'] : 'unknown';
                $cve = is_string($a['cve'] ?? null) ? $a['cve'] : 'N/A';
                $link = is_string($a['link'] ?? null) ? $a['link'] : 'N/A';
                $cveOrLink = is_string($a['cve'] ?? null) ? $a['cve'] : (is_string($a['link'] ?? null) ? $a['link'] : 'N/A');
                $files[] = $title.' ('.$cveOrLink.')';
                $detailList[] = [
                    'package' => is_string($a['package'] ?? null) ? $a['package'] : 'unknown',
                    'severity' => is_string($a['severity'] ?? null) ? $a['severity'] : 'unknown',
                    'title' => $title,
                    'cve' => $cve,
                    'link' => $link,
                ];
            }
            $actions = [[
                'type' => ActionType::FixSecurity,
                'message' => sprintf('Fix %d critical/high security advisories', $criticalCount),
                'files' => $files,
            ]];
            $details = $detailList;
        }

        return new GateResult(
            status: $status,
            name: 'security',
            label: 'Security Audit',
            message: sprintf('%d total advisories, %d critical/high', $totalCount, $criticalCount),
            severity: Severity::Block,
            baseline: ['advisories' => $baselineAdvisoryCount],
            current: ['advisories' => $totalCount, 'critical' => $criticalCount],
            actions: $actions,
            details: $details,
        );
    }
}
