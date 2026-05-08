<?php

namespace B7S\RatchetBabysit\Enum;

enum Status: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';
    case Warn = 'warn';
}
