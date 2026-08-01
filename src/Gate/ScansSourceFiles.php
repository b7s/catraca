<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_key_exists;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function mb_strimwidth;
use function pathinfo;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

trait ScansSourceFiles
{
    /** @var array<string, array<int, string>> */
    private array $fileScanCache = [];

    /** @var array<string, array<int, string>|null> */
    private array $fileLinesCache = [];

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
        $cacheKey =
            implode("\0", $sourcePaths) . "\1" . implode("\0", $extensions) . "\1" . implode("\0", $excludePaths);
        if (isset($this->fileScanCache[$cacheKey])) {
            return $this->fileScanCache[$cacheKey];
        }

        $result = [];

        foreach ($sourcePaths as $dir) {
            if (is_file($dir)) {
                if (in_array(pathinfo($dir, PATHINFO_EXTENSION), $extensions, true)) {
                    $result[] = $dir;
                }

                continue;
            }

            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (!in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }

                $pathname = $file->getPathname();
                if ($this->isExcludedPath($pathname, $excludePaths)) {
                    continue;
                }

                $result[] = $pathname;
            }
        }

        $this->fileScanCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param  array<int, string>  $excludePaths
     */
    private function isExcludedPath(string $pathname, array $excludePaths): bool
    {
        foreach ($excludePaths as $exclude) {
            if (str_contains($pathname, '/' . $exclude . '/') || str_contains($pathname, '\\' . $exclude . '\\')) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $root, string $pathname): string
    {
        return str_replace($root . '/', '', $pathname);
    }

    /**
     * @return array<int, string>|null
     */
    private function readFileLines(string $pathname): ?array
    {
        if (array_key_exists($pathname, $this->fileLinesCache)) {
            return $this->fileLinesCache[$pathname];
        }

        $content = file_get_contents($pathname);
        $this->fileLinesCache[$pathname] = $content === false ? null : explode("\n", $content);

        return $this->fileLinesCache[$pathname];
    }

    private function fmt(string $root, string $pathname, int $lineIndex, string $line, string $description): string
    {
        $relative = $this->relativePath($root, $pathname);

        return (
            "{$relative}:" . ($lineIndex + 1) . ' — ' . $description . ': ' . mb_strimwidth(trim($line), 0, 120, '…')
        );
    }

    private function isLangFile(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'lang/') || str_starts_with($relativePath, 'resources/lang/');
    }

    private function isDatabaseFile(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'database/');
    }

    /**
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

            foreach ($lines as $index => $line) {
                yield [$pathname, $index, $line];
            }
        }
    }
}
