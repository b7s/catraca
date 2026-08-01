<?php

declare(strict_types=1);

namespace B7S\Catraca;

use InvalidArgumentException;

use function array_keys;
use function implode;
use function in_array;
use function sprintf;

final class GateToolRegistry
{
    public const string DEFAULT = 'auto';

    public const string MAGO_VERSION = '1.45.0';

    public const array FALLBACKS = [
        'style' => ['mago', 'pint', 'php-cs-fixer'],
        'static_analysis' => ['mago', 'phpstan', 'psalm'],
        'coverage' => ['pest', 'phpunit'],
        'performance' => ['mago', 'php-cs-fixer'],
    ];

    private const array MAGO_CAPABILITIES = [
        'style' => 'format',
        'static_analysis' => 'analyze',
        'performance' => 'lint',
    ];

    public static function resolve(Baseline $baseline, ToolResolver $resolver, string $gate): ?GateTool
    {
        foreach (self::candidates($baseline, $gate) as $name) {
            if ($name === 'mago' && !$baseline->isMagoEnabled(self::MAGO_CAPABILITIES[$gate])) {
                continue;
            }

            $path = $resolver->resolve($name);
            if ($path !== null) {
                return new GateTool($name, $path);
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public static function candidates(Baseline $baseline, string $gate): array
    {
        $fallbacks = self::FALLBACKS[$gate] ?? null;
        if ($fallbacks === null) {
            throw new InvalidArgumentException(sprintf('Unknown quality gate "%s".', $gate));
        }

        $selected = $baseline->getGateTool($gate);
        if ($selected === self::DEFAULT) {
            return $fallbacks;
        }

        if (!in_array($selected, $fallbacks, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid tool "%s" for %s. Use one of: %s.',
                $selected,
                $gate,
                implode(', ', [self::DEFAULT, ...$fallbacks]),
            ));
        }

        return [$selected];
    }

    /** @return array<int, string> */
    public static function gates(): array
    {
        return array_keys(self::FALLBACKS);
    }

    public static function description(string $gate): string
    {
        $fallbacks = self::FALLBACKS[$gate] ?? [];

        return implode(' -> ', $fallbacks);
    }
}
