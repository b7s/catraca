<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests\Output;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\Output\JunitFormatter;
use B7S\Catraca\Output\SarifFormatter;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function simplexml_load_string;

final class CiFormatterTest extends TestCase
{
    public function test_sarif_contains_stable_rule_and_location(): void
    {
        $result = $this->checkResult();
        $sarif = json_decode((new SarifFormatter())->format($result), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('2.1.0', $sarif['version']);
        self::assertSame('catraca.static_analysis', $sarif['runs'][0]['results'][0]['ruleId']);
        self::assertSame(
            'src/Foo.php',
            $sarif['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'],
        );
    }

    public function test_junit_maps_failed_gate_to_failure(): void
    {
        $xml = simplexml_load_string((new JunitFormatter())->format($this->checkResult()));

        self::assertNotFalse($xml);
        self::assertSame('1', (string) $xml['failures']);
        self::assertCount(1, $xml->testcase->failure);
    }

    private function checkResult(): CheckResult
    {
        $result = new CheckResult();
        $result->add(new GateResult(
            status: Status::Fail,
            name: 'static_analysis',
            label: 'Static Analysis',
            message: 'One error',
            details: ['errors' => [['message' => 'src/Foo.php:12 bad type']]],
        ));

        return $result;
    }
}
