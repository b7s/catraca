<?php

namespace B7S\RatchetBabysit\Gate;

use B7S\RatchetBabysit\Baseline;
use B7S\RatchetBabysit\Enum\ActionType;
use B7S\RatchetBabysit\Enum\Severity;
use B7S\RatchetBabysit\Enum\Status;
use B7S\RatchetBabysit\GateResult;
use B7S\RatchetBabysit\ToolResolver;
use Symfony\Component\Process\Process;

class SecurityGate
{
    public function run(Baseline $baseline, ToolResolver $resolver): GateResult
    {
        $composer = $resolver->resolve('composer') ?? 'composer';

        $process = new Process([$composer, 'audit', '--format=json']);
        $process->setWorkingDirectory($baseline->projectRoot ?? getcwd());
        $process->run();

        $output = $process->getOutput();
        $data = json_decode($output, true);

        if (!is_array($data)) {
            return new GateResult(
                status: Status::Skip,
                name: 'security',
                label: 'Security Audit',
                message: 'Could not parse composer audit output',
                severity: Severity::Warn,
            );
        }

        $advisories = $data['advisories'] ?? [];
        $critical = array_filter($advisories, fn($a) => in_array(($a['severity'] ?? ''), ['critical', 'high'], true));
        $criticalCount = count($critical);
        $totalCount = count($advisories);

        $baselineAdvisoryCount = $baseline->get('security', 'advisories', 0);

        $actions = null;
        $details = null;
        $status = Status::Pass;

        if ($criticalCount > 0) {
            $status = Status::Fail;
            $actions = [[
                'type' => ActionType::FixSecurity,
                'message' => sprintf('Fix %d critical/high security advisories', $criticalCount),
                'files' => array_map(fn($a) => ($a['title'] ?? 'unknown') . ' (' . ($a['cve'] ?? $a['link'] ?? 'N/A') . ')', $critical),
            ]];
            $details = array_map(fn($a) => [
                'package' => $a['package'] ?? 'unknown',
                'severity' => $a['severity'] ?? 'unknown',
                'title' => $a['title'] ?? '',
                'cve' => $a['cve'] ?? null,
                'link' => $a['link'] ?? null,
            ], $critical);
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
