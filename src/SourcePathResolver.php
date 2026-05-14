<?php

namespace B7S\Catraca;

use function is_dir;

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
            if (is_dir($projectRoot.'/'.$dir)) {
                $paths[] = $projectRoot.'/'.$dir;
            }
        }

        return $paths !== [] ? $paths : [$projectRoot];
    }
}
