<?php

declare(strict_types=1);

namespace B7S\Catraca;

use function array_key_exists;
use function is_array;
use function is_string;
use function strtolower;

final class ToolConfigMigrator
{
    private const array LEGACY_GATE_OPERATIONS = [
        'style' => 'format',
        'static_analysis' => 'analyze',
        'coverage' => 'coverage',
        'performance' => 'lint',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function migrate(array $data): array
    {
        $config = self::object($data['config'] ?? null);
        $tools = self::object($config['tools'] ?? null);

        foreach (self::LEGACY_GATE_OPERATIONS as $gate => $operation) {
            $gateConfig = self::object($config[$gate] ?? null);
            $legacyTool = $gateConfig['tool'] ?? null;
            if (
                ($tools[$operation] ?? GateToolRegistry::DEFAULT) === GateToolRegistry::DEFAULT
                && is_string($legacyTool)
            ) {
                $tools[$operation] = strtolower($legacyTool);
            }

            unset($gateConfig['tool']);
            $config[$gate] = $gateConfig;
        }

        $legacyMago = self::object($config['mago'] ?? null);
        $options = self::object($tools['options'] ?? null);
        $magoOptions = self::object($options['mago'] ?? null);

        foreach (['threads', 'minimum_report_level', 'minimum_version'] as $key) {
            if (!array_key_exists($key, $magoOptions) && array_key_exists($key, $legacyMago)) {
                $magoOptions[$key] = $legacyMago[$key];
            }
        }

        if (!array_key_exists('minimum_version', $magoOptions) && is_string($legacyMago['version'] ?? null)) {
            $magoOptions['minimum_version'] = $legacyMago['version'];
        }

        self::migrateDisabledMagoCapabilities($tools, $legacyMago);

        if ($magoOptions !== []) {
            $options['mago'] = $magoOptions;
        }
        if ($options !== []) {
            $tools['options'] = $options;
        }

        unset($config['mago']);
        $config['tools'] = $tools;
        $data['config'] = $config;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $tools
     * @param  array<string, mixed>  $legacyMago
     */
    private static function migrateDisabledMagoCapabilities(array &$tools, array $legacyMago): void
    {
        $enabled = ($legacyMago['enabled'] ?? true) === true;
        foreach ([
            'format' => 'style',
            'analyze' => 'static_analysis',
            'lint' => 'performance',
        ] as $operation => $gate) {
            $capabilityEnabled = ($legacyMago[$operation] ?? true) === true;
            $selection = $tools[$operation] ?? GateToolRegistry::DEFAULT;
            if ($enabled && $capabilityEnabled || $selection !== GateToolRegistry::DEFAULT) {
                continue;
            }

            $tools[$operation] = GateToolRegistry::FALLBACKS[$gate][1];
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
