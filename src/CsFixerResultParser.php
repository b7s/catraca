<?php

namespace B7S\Catraca;

use function count;
use function is_array;
use function is_string;

class CsFixerResultParser
{
    /**
     * @return array{violations: int, files: array<int, string>}
     */
    public static function parseJsonOutput(string $output): array
    {
        $files = [];

        $data = json_decode($output, true);
        if (is_array($data)) {
            $fileEntries = $data['files'] ?? $data;

            if (is_array($fileEntries)) {
                foreach ($fileEntries as $value) {
                    if (!is_array($value)) {
                        continue;
                    }
                    $filePath = $value['name'] ?? $value['file'] ?? null;
                    if (is_string($filePath)) {
                        $files[] = $filePath;
                    }
                }
            }
        }

        return ['violations' => count($files), 'files' => $files];
    }
}
