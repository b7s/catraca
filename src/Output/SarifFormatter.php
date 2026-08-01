<?php

declare(strict_types=1);

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\Status;
use JsonException;

use function array_map;
use function json_encode;
use function preg_match;

final readonly class SarifFormatter
{
    /** @throws JsonException */
    public function format(CheckResult $result): string
    {
        $findings = [];

        foreach ($result->getGates() as $gate) {
            if ($gate->status === Status::Pass || $gate->status === Status::Skip) {
                continue;
            }

            $location = $this->location($gate->details);
            $finding = [
                'ruleId' => 'catraca.' . $gate->name,
                'level' => $gate->status === Status::Fail ? 'error' : 'warning',
                'message' => ['text' => $gate->message],
            ];
            if ($location !== null) {
                $finding['locations'] = [['physicalLocation' => $location]];
            }
            $findings[] = $finding;
        }

        return json_encode(
            [
                'version' => '2.1.0',
                '$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
                'runs' => [[
                    'tool' => ['driver' => [
                        'name' => 'Catraca',
                        'informationUri' => 'https://github.com/b7s/catraca',
                        'rules' => array_map(static fn($gate): array => [
                            'id' => 'catraca.' . $gate->name,
                            'shortDescription' => ['text' => $gate->label],
                        ], $result->getGates()),
                    ]],
                    'results' => $findings,
                ]],
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    /** @param array<string, mixed>|null $details */
    private function location(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || preg_match('~([A-Za-z0-9_./-]+\.php):(\d+)~', $encoded, $match) !== 1) {
            return null;
        }

        return [
            'artifactLocation' => ['uri' => $match[1]],
            'region' => ['startLine' => (int) $match[2]],
        ];
    }
}
