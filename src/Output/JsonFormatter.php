<?php

namespace B7S\RatchetBabysit\Output;

use B7S\RatchetBabysit\CheckResult;

class JsonFormatter
{
    public function format(CheckResult $result): string
    {
        return json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
