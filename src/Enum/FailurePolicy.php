<?php

declare(strict_types=1);

namespace B7S\Catraca\Enum;

enum FailurePolicy: string
{
    case Fail = 'fail';
    case Warn = 'warn';
    case Skip = 'skip';
}
