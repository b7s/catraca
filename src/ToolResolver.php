<?php

namespace B7S\Catraca;

class ToolResolver
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function resolve(string $tool): ?string
    {
        $candidates = [
            $this->projectRoot . '/vendor/bin/' . $tool,
            $tool,
            $this->getComposerGlobalBin() . '/' . $tool,
        ];

        foreach ($candidates as $candidate) {
            if ($this->isExecutable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function resolveOrFail(string $tool): string
    {
        $resolved = $this->resolve($tool);
        if ($resolved === null) {
            throw new \RuntimeException(
                sprintf('Tool "%s" not found. Install it locally (composer require --dev), globally (composer global require), or as a standalone PHAR.', $tool)
            );
        }
        return $resolved;
    }

    public function resolvePhp(): string
    {
        $candidates = [
            $this->projectRoot . '/vendor/bin/php',
            PHP_BINARY,
            'php',
        ];

        foreach ($candidates as $candidate) {
            if ($this->isExecutable($candidate)) {
                return $candidate;
            }
        }

        return PHP_BINARY;
    }

    private function getComposerGlobalBin(): string
    {
        $home = getenv('COMPOSER_HOME');
        if (is_string($home) && $home !== '') {
            return $home . '/vendor/bin';
        }

        $fallback = (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux')
            ? ($_SERVER['HOME'] ?? '~/')
            : (getenv('APPDATA') ?: '~/');

        return (is_string($fallback) ? $fallback : '~/') . '/vendor/bin';
    }

    private function isExecutable(string $path): bool
    {
        if (file_exists($path)) {
            return is_executable($path);
        }

        return $this->isInPath($path);
    }

    private function isInPath(string $command): bool
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $fullPath = $dir . DIRECTORY_SEPARATOR . $command;
            if (file_exists($fullPath) && is_executable($fullPath)) {
                return true;
            }
        }

        return false;
    }
}
