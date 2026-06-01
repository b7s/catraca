<?php

namespace B7S\Catraca\Gate;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function explode;
use function file_get_contents;
use function in_array;
use function is_dir;
use function mb_strimwidth;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

trait ScansSourceFiles
{
    /**
     * @param  array<int, string>  $sourcePaths
     * @param  array<int, string>  $excludePaths
     * @return array<int, string>
     */
    private function scanPhpFiles(array $sourcePaths, array $excludePaths): array
    {
        return $this->scanFiles($sourcePaths, ['php'], $excludePaths);
    }

    /**
     * @param  array<int, string>  $sourcePaths
     * @param  array<int, string>  $extensions
     * @param  array<int, string>  $excludePaths
     * @return array<int, string>
     */
    private function scanFiles(array $sourcePaths, array $extensions, array $excludePaths): array
    {
        $result = [];

        foreach ($sourcePaths as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! ($file instanceof SplFileInfo) || ! $file->isFile()) {
                    continue;
                }

                $ext = $file->getExtension();
                if (! in_array($ext, $extensions, true)) {
                    continue;
                }

                $pathname = $file->getPathname();
                if ($this->isExcludedPath($pathname, $excludePaths)) {
                    continue;
                }

                $result[] = $pathname;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $excludePaths
     */
    private function isExcludedPath(string $pathname, array $excludePaths): bool
    {
        foreach ($excludePaths as $exclude) {
            if (str_contains($pathname, '/'.$exclude.'/') || str_contains($pathname, '\\'.$exclude.'\\')) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $root, string $pathname): string
    {
        return str_replace($root.'/', '', $pathname);
    }

    /**
     * @return array<int, string>|null
     */
    private function readFileLines(string $pathname): ?array
    {
        $content = file_get_contents($pathname);
        if ($content === false) {
            return null;
        }

        return explode("\n", $content);
    }

    private function fmt(string $root, string $pathname, int $lineIndex, string $line, string $description): string
    {
        $relative = $this->relativePath($root, $pathname);

        return "{$relative}:".($lineIndex + 1).' — '.$description.': '.mb_strimwidth(trim($line), 0, 120, '…');
    }

    private function isLangFile(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'lang/')
            || str_starts_with($relativePath, 'resources/lang/');
    }

    private function isDatabaseFile(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'database/');
    }

    /**
     * Yields [$pathname, $lineIndex, $line] tuples for every line in every PHP file.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludePaths
     * @return Generator<int, array{0: string, 1: int, 2: string}>
     */
    private function scanLines(array $paths, array $excludePaths): Generator
    {
        foreach ($this->scanPhpFiles($paths, $excludePaths) as $pathname) {
            $lines = $this->readFileLines($pathname);
            if ($lines === null) {
                continue;
            }

            foreach ($lines as $i => $line) {
                yield [$pathname, $i, $line];
            }
        }
    }
}
