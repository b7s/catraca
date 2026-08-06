<?php

declare(strict_types=1);

namespace B7S\Catraca\Gate;

use Symfony\Component\Process\Process;

use function file_get_contents;
use function is_array;
use function is_executable;
use function is_string;
use function json_decode;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

/**
 * Scans the repository for hardcoded secrets using gitleaks.
 *
 * Gitleaks is an optional, git-aware, cross-language secret scanner. When the
 * binary is not installed the scan is skipped silently, matching catraca's
 * `missing_tool: skip` policy. Standard noise paths (third-party code, caches,
 * VCS metadata) are filtered out of the findings so they never block the gate.
 *
 * The consumer project controls detection rules and allowlists through its own
 * `.gitleaks.toml` at the repository root; catraca auto-discovers it via
 * gitleaks' `--source` config lookup.
 *
 * @see https://github.com/gitleaks/gitleaks
 */
final class GitleaksScanner
{
    /** Paths whose contents are third-party or generated and must never block the gate. */
    private const array EXCLUDED_PATHS = [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        '.git',
    ];

    public function __construct(
        private readonly string $root,
    ) {}

    /** @return array<int, string> */
    public function scan(): array
    {
        $binary = $this->resolveBinary('gitleaks');
        if ($binary === null) {
            return [];
        }

        $reportPath = sys_get_temp_dir() . '/catraca-gitleaks-' . uniqid('', true) . '.json';
        $process = new Process(
            [
                $binary,
                'detect',
                '--no-git',
                '--no-banner',
                '--redact',
                '--report-format',
                'json',
                '--report-path',
                $reportPath,
                '--source',
                $this->root,
            ],
            $this->root,
            timeout: 180,
        );
        $process->run();

        $raw = @file_get_contents($reportPath);
        if (is_string($raw)) {
            @unlink($reportPath);
        }

        $report = json_decode(is_string($raw) ? $raw : '[]', true);
        if (!is_array($report)) {
            return [];
        }

        $findings = [];
        foreach ($report as $item) {
            if (!is_array($item)) {
                continue;
            }

            $file = (string) ($item['File'] ?? '');
            if ($file === '' || $this->isExcludedPath($file)) {
                continue;
            }

            $rule = (string) ($item['RuleID'] ?? 'unknown');
            $line = (int) ($item['StartLine'] ?? 0);
            $description = (string) ($item['Description'] ?? '');
            $findings[] = sprintf('[gitleaks:%s] %s:%d %s', $rule, $file, $line, $description);
        }

        return $findings;
    }

    /**
     * Resolves an optional external binary: prefers a project-local
     * `vendor/bin/<name>`, then falls back to `$PATH` via `which`.
     */
    private function resolveBinary(string $name): ?string
    {
        $local = $this->root . '/vendor/bin/' . $name;
        if (is_executable($local)) {
            return $local;
        }

        $which = new Process(['which', $name]);
        $which->run();
        if (!$which->isSuccessful()) {
            return null;
        }

        $path = trim($which->getOutput());

        return $path === '' ? null : $path;
    }

    private function isExcludedPath(string $relativeFile): bool
    {
        foreach (self::EXCLUDED_PATHS as $exclude) {
            if ($relativeFile === $exclude || str_starts_with($relativeFile, $exclude . '/')) {
                return true;
            }
        }

        return false;
    }
}
