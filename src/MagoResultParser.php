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
            /** @var array{issues?: list<array{message?: mixed, code?: mixed, level?: mixed, annotations?: mixed}>}|mixed $data */
            $data = json_decode(substr($output, $jsonStart), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !is_array($data['issues'] ?? null)) {
            return null;
        }

        $issues = [];
        /** @var list<array{message?: mixed, code?: mixed, level?: mixed, annotations?: mixed}> $issuesRaw */
        $issuesRaw = $data['issues'];
        foreach ($issuesRaw as $issue) {
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
        /** @var array<int, array{span?: mixed}> $annotations */
        $annotations = $issue['annotations'] ?? [];
        foreach ($annotations as $annotation) {
            if (!is_array($annotation)) {
                continue;
            }

            /** @var array{span?: mixed} $annotation */
            $span = $annotation['span'] ?? [];
            if (!is_array($span)) {
                continue;
            }

            /** @var array{name?: mixed, path?: mixed} $fileId */
            $fileId = $span['file_id'] ?? [];
            /** @var array{line?: mixed} $start */
            $start = $span['start'] ?? [];

            $file = is_string($fileId['name'] ?? null)
                ? $fileId['name']
                : (is_string($fileId['path'] ?? null) ? $fileId['path'] : 'unknown');
            $line = is_int($start['line'] ?? null) ? $start['line'] : 0;

            return [$file, $line];
        }

        return ['unknown', 0];
    }
}
