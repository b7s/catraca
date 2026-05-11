<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\CheckResult;
use JsonException;

class JsonFormatter
{
    /**
     * @throws JsonException
     */
    public function format(CheckResult $result, bool $asPretty = false): string
    {
        $encoded = json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($asPretty ? JSON_PRETTY_PRINT : 0));

        return ($encoded !== false ? $encoded : '{}')."\n";
    }
}
