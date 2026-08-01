<?php

declare(strict_types=1);

namespace B7S\Catraca\Enum;

enum PolicyMode: string
{
    case Absolute = 'absolute';
    case NoRegression = 'no_regression';
    case Informational = 'informational';
}
