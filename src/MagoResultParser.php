<?php

declare(strict_types=1);

namespace B7S\Catraca;

use JsonException;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function strpos;
use function substr;
use function trim;

final class MagoResultParser
{
    /**
     * @return array<int, array{file: string, line: int, message: string, code: string, level: string}>|null
     */
    public static function parse(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        $jsonStart = strpos($output, '{');
        if ($jsonStart === false) {
            return null;
        }

        try {
            $data = json_decode(substr($output, $jsonStart), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !is_array($data['issues'] ?? null)) {
            return null;
        }

        $issues = [];
        foreach ($data['issues'] as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            [$file, $line] = self::location($issue);
            $issues[] = [
                'file' => $file,
                'line' => $line,
                'message' => is_string($issue['message'] ?? null) ? $issue['message'] : '',
                'code' => is_string($issue['code'] ?? null) ? $issue['code'] : '',
                'level' => is_string($issue['level'] ?? null) ? $issue['level'] : 'error',
            ];
        }

        return $issues;
    }

    /** @return array{0: string, 1: int} */
    private static function location(array $issue): array
    {
        $annotations = $issue['annotations'] ?? [];
        if (!is_array($annotations)) {
            return ['unknown', 0];
        }

        foreach ($annotations as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }

            $span = $annotation['span'] ?? [];
            $fileId = is_array($span) ? $span['file_id'] ?? [] : [];
            $start = is_array($span) ? $span['start'] ?? [] : [];
            if (!is_array($fileId) || !is_array($start)) {
                continue;
            }

            $file = is_string($fileId['name'] ?? null)
                ? $fileId['name']
                : (is_string($fileId['path'] ?? null) ? $fileId['path'] : 'unknown');
            $line = is_int($start['line'] ?? null) ? $start['line'] : 0;

            return [$file, $line];
        }

        return ['unknown', 0];
    }
}
