<?php

declare(strict_types=1);

namespace B7S\Catraca;

use Symfony\Component\Process\Process;
use Throwable;

use function preg_match;
use function version_compare;

final class MagoVersionChecker
{
    public function detect(string $binary, string $projectRoot): ?string
    {
        try {
            $process = new Process([$binary, '--version'], $projectRoot, timeout: 10.0);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput() . $process->getErrorOutput();
        if (preg_match('/\bmago\s+v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)/i', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function isValid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1;
    }

    public static function satisfies(string $version, string $minimum): bool
    {
        return self::isValid($version) && self::isValid($minimum) && version_compare($version, $minimum, '>=');
    }
}
