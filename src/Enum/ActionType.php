<?php

namespace B7S\RatchetBabysit\Enum;

enum ActionType: string
{
    case Escalate = 'ESCALATE';
    case FixStyle = 'FIX STYLE';
    case FixSA = 'FIX SA';
    case AddTests = 'ADD TESTS';
    case RefactorDup = 'REFACTOR DUP';
    case Modularize = 'MODULARIZE';
    case FixSecurity = 'FIX SECURITY';
}
