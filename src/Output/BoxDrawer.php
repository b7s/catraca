<?php

namespace B7S\Catraca\Output;

use function mb_strlen;
use function sprintf;
use function str_repeat;

trait BoxDrawer
{
    private function box(string $text): string
    {
        $len = mb_strlen($text);

        return sprintf("  ┌%s┐\n  │ %s │\n  └%s┘", str_repeat('─', $len + 2), $text, str_repeat('─', $len + 2));
    }

    private function divider(int $width = 60): string
    {
        return '  '.str_repeat('─', $width);
    }
}
