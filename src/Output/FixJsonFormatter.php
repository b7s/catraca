<?php

namespace B7S\Catraca\Output;

use B7S\Catraca\FixResult;
use JsonException;

class FixJsonFormatter
{
    /**
     * @throws JsonException
     */
    public function format(FixResult $result, bool $asPretty = false): string
    {
        $encoded = json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | ($asPretty ? JSON_PRETTY_PRINT : 0));

        return $encoded."\n";
    }
}
