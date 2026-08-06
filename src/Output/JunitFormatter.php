<?php

declare(strict_types=1);

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;

use function count;
use function htmlspecialchars;
use function sprintf;

final readonly class JunitFormatter
{
    public function format(CheckResult $result): string
    {
        $lines = [sprintf(
            '<testsuite name="catraca" tests="%d" failures="%d" skipped="%d">',
            count($result->getGates()),
            $result->getFailedCount(),
            $result->getSkippedCount(),
        )];

        $time = $result->getTime();
        $memory = $result->getMemory();
        if ($time !== null || $memory !== null) {
            $lines[] = '  <properties>';
            if ($time !== null) {
                $lines[] = sprintf('    <property name="time" value="%s"/>', htmlspecialchars($time, ENT_XML1));
            }
            if ($memory !== null) {
                $lines[] = sprintf('    <property name="memory" value="%s"/>', htmlspecialchars($memory, ENT_XML1));
            }
            $lines[] = '  </properties>';
        }

        foreach ($result->getGates() as $gate) {
            $lines[] = sprintf('  <testcase classname="catraca" name="%s">', htmlspecialchars($gate->label, ENT_XML1));

            if ($gate->status === Status::Fail || $gate->status === Status::Cancelled) {
                $lines[] = sprintf('    <failure message="%s"/>', htmlspecialchars($gate->message, ENT_XML1));
            } elseif ($gate->status === Status::Skip) {
                $lines[] = sprintf('    <skipped message="%s"/>', htmlspecialchars($gate->message, ENT_XML1));
            } elseif ($gate->status === Status::Warn) {
                $lines[] = sprintf('    <system-out>%s</system-out>', htmlspecialchars(
                    'WARNING: ' . $gate->message,
                    ENT_XML1,
                ));
            }

            $lines[] = '  </testcase>';
        }

        $lines[] = '</testsuite>';

        return implode("\n", $lines) . "\n";
    }
}
