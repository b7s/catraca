<?php

namespace B7S\Catraca;

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
            foreach ($data as $value) {
                if (is_array($value) && isset($value['file']) && is_string($value['file'])) {
                    $files[] = $value['file'];
                }
            }
        }

        return ['violations' => count($files), 'files' => $files];
    }
}
