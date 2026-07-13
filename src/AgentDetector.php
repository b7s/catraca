<?php

declare(strict_types=1);

namespace B7S\Catraca;

use function file_exists;
use function getenv;
use function trim;

// Copyright (c) Pushpak Chhajed pushpak1300@gmail.com
// from https://github.com/shipfastlabs/agent-detector/blob/98766473b2dfe183b0c2605ceb04e587a78d1872/src/AgentDetector.php
final class AgentDetector
{
    /** @var list<string> */
    public const array ENV_VARS = [
        'AUGMENT_AGENT',
        'AUGMENT_SESSION_ID',
        'AMP_CURRENT_THREAD_ID',
        'AI_AGENT',
        'CURSOR_TRACE_ID',
        'CURSOR_AGENT',
        'GEMINI_CLI',
        'CODEX_SANDBOX',
        'CODEX_THREAD_ID',
        'OPENCODE_CLIENT',
        'OPENCODE',
        'CLAUDECODE',
        'CLAUDE_CODE',
        'REPL_ID',
    ];

    public static function isRunningInAgent(): bool
    {
        foreach (self::ENV_VARS as $envVar) {
            $value = getenv($envVar);
            if ($value === false) {
                continue;
            }

            if ($envVar === 'AI_AGENT' && trim($value) === '') {
                continue;
            }

            return true;
        }

        if (@file_exists('/opt/.devin')) {
            return true;
        }

        return false;
    }
}
