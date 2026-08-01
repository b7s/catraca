<?php

declare(strict_types=1);

namespace B7S\Catraca;

use Symfony\Component\Process\Process;

use function array_filter;
use function array_map;
use function explode;
use function is_file;
use function str_ends_with;
use function trim;

final readonly class ChangedFileResolver
{
    /** @return array<int, string> */
    public function resolve(string $projectRoot, string $reference): array
    {
        $process = new Process(
            ['git', 'diff', '--name-only', '--diff-filter=ACMRT', $reference . '...HEAD'],
            $projectRoot,
            timeout: 15,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $files = array_filter(
            explode("\n", trim($process->getOutput())),
            static fn(string $path): bool => $path !== '' && str_ends_with($path, '.php'),
        );

        return array_values(array_filter(
            array_map(static fn(string $path): string => $projectRoot . '/' . $path, $files),
            static fn(string $path): bool => is_file($path),
        ));
    }
}
