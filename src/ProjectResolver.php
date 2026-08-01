<?php

namespace B7S\Catraca;

use function is_dir;
use function realpath;
use function sprintf;

class ProjectResolver
{
    /**
     * Resolve a raw path string into a validated project root directory.
     */
    public function resolve(?string $rawPath): ?string
    {
        $rawPath = $rawPath ?? (string) getcwd();
        $projectRoot = realpath($rawPath);

        if ($projectRoot === false || !is_dir($projectRoot)) {
            return null;
        }

        return $projectRoot;
    }

    public function getErrorMessage(string $rawPath): string
    {
        return sprintf('<error>Directory not found: %s</error>', $rawPath);
    }
}
