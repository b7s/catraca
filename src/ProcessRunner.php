<?php

namespace B7S\Catraca;

use Symfony\Component\Process\Process;

class ProcessRunner
{
    /**
     * @param  array<int, string>  $command
     * @return array{success: bool, output: string, errorOutput: string}
     */
    public function run(array $command): array
    {
        $process = new Process($command);
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'errorOutput' => trim($process->getErrorOutput() ?: $process->getOutput()),
        ];
    }
}
