<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\AgentDetector;
use B7S\Catraca\CheckResult;
use B7S\Catraca\Enum\ActionType;
use JsonException;

class JsonFormatter
{
    /**
     * @throws JsonException
     */
    public function format(CheckResult $result, bool $asPretty = false): string
    {
        $data = $result->toArray();

        if (AgentDetector::isRunningInAgent() && ! $result->isPass()) {
            $fixAction = [
                'type' => ActionType::RunFix->value,
                'priority' => 0,
                'message' => 'Run `./vendor/bin/catraca fix` to auto-fix code style and performance issues',
                'files' => [],
                'reasons' => [],
            ];

            array_unshift($data['actions'], $fixAction);

            foreach ($data['actions'] as $i => $action) {
                $data['actions'][$i]['priority'] = $i;
            }
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($asPretty ? JSON_PRETTY_PRINT : 0))."\n";
    }
}
