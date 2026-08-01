<?php

declare(strict_types=1);

namespace B7S\Catraca;

use function array_filter;
use function array_merge;
use function glob;
use function is_dir;
use function is_file;
use function str_contains;

class SourcePathResolver
{
    /**
     * @param  array<int, string>  $sourceDirs
     * @return array<int, string>
     */
    public function resolve(string $projectRoot, array $sourceDirs): array
    {
        $paths = [];
        foreach ($sourceDirs as $dir) {
            $candidate = $projectRoot . '/' . $dir;
            $matches = glob($candidate, GLOB_ONLYDIR);
            if ($matches !== false && $matches !== []) {
                $paths = array_merge($paths, $matches);

                continue;
            }
            if (is_dir($candidate) || is_file($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths !== [] ? $paths : [$projectRoot];
    }

    /** @return array<int, string> */
    public function resolveForBaseline(Baseline $baseline): array
    {
        $changedFrom = $baseline->getChangedFrom();
        $paths = $changedFrom === null
            ? $this->resolve($baseline->projectRoot, $baseline->getSourceDirs())
            : (new ChangedFileResolver())->resolve($baseline->projectRoot, $changedFrom);

        return array_values(array_filter(
            $paths,
            fn(string $path): bool => !$this->isExcluded($path, $baseline->getExcludePaths()),
        ));
    }

    /** @param array<int, string> $excludes */
    private function isExcluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (str_contains($path, '/' . $exclude . '/') || str_contains($path, '\\' . $exclude . '\\')) {
                return true;
            }
        }

        return false;
    }
}
