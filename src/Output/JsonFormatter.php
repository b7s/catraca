<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;

class JsonFormatter
{
    public function format(CheckResult $result): string
    {
        return json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
