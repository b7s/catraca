<?php

namespace B7S\Catraca\Enum;

enum Status: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';
    case Warn = 'warn';
    case Cancelled = 'cancelled';
}
