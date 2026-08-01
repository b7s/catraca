<?php

declare(strict_types=1);

namespace B7S\Catraca;

use JsonException;
use RuntimeException;

use function array_slice;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_encode;
use function mkdir;
use function sprintf;
use function substr;
use function unlink;

final readonly class RunHistoryStore
{
    /** @throws JsonException */
    public function write(CheckResult $result, string $projectRoot, string $profile, int $retention = 50): string
    {
        $directory = $projectRoot . '/.catraca/runs';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create run history directory: %s', $directory));
        }

        $stamp = $result->timestamp->format('Ymd-His-u');
        $configHash = (new Baseline($projectRoot, profile: $profile))->getConfigHash();
        $path = sprintf('%s/%s-%s-%s.json', $directory, $stamp, $profile, substr($configHash, 0, 12));
        $payload = $result->toArray();
        $payload['config_hash'] = $configHash;
        $payload['profile'] = $profile;

        file_put_contents(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX,
        );

        $files = glob($directory . '/*.json') ?: [];
        rsort($files);
        foreach (array_slice($files, $retention) as $old) {
            unlink($old);
        }

        return $path;
    }
}
