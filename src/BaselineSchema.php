<?php

declare(strict_types=1);

namespace B7S\Catraca;

use B7S\Catraca\Gate\SecurityGate;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

final class BaselineSchema
{
    /** @var array<string, array<int, string>> */
    private const array RESULT_KEYS = [
        'security' => ['advisories', 'findings', 'critical'],
        'style' => ['violations'],
        'static_analysis' => ['errors'],
        'coverage' => ['percentage'],
        'duplication' => ['percentage', 'clones'],
        'file_size' => ['over_limit'],
        'complexity' => ['max_ccn', 'violations', 'warnings'],
        'performance' => ['violations'],
    ];

    private const array DEFAULT_SOURCE_DIRS = ['src', 'app', 'lib'];

    public const int DEFAULT_MAX_PROCESSES = 4;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'schema' => Baseline::SCHEMA,
            'config' => [
                'source_dirs' => [
                    'paths' => self::DEFAULT_SOURCE_DIRS,
                    'exclude' => ['vendor', '.git', 'node_modules'],
                ],
                'policy' => [
                    'missing_tool' => 'skip',
                    'unavailable_metric' => 'warn',
                    'internal_error' => 'fail',
                ],
                'process' => ['timeout_seconds' => 1200],
                'mago' => [
                    'enabled' => true,
                    'format' => true,
                    'analyze' => true,
                    'lint' => true,
                    'threads' => 0,
                    'minimum_report_level' => 'warning',
                    'version' => GateToolRegistry::MAGO_VERSION,
                ],
                'history' => ['enabled' => false, 'retention' => 50],
                'security' => [
                    'mode' => 'no_regression',
                    'rules' => SecurityGate::DEFAULT_RULES,
                    'fixers' => [],
                    'released_days' => 3,
                ],
                'duplication' => [
                    'mode' => 'no_regression',
                    'max_percentage' => 0.0,
                    'min_lines' => 3,
                    'min_tokens' => 30,
                ],
                'performance' => [
                    'mode' => 'no_regression',
                    'tool' => GateToolRegistry::DEFAULT,
                    'rules' => [
                        'global_namespace_import' => true,
                        'no_unused_imports' => true,
                        'fully_qualified_strict_types' => true,
                        'lambda_not_used_import' => true,
                        'native_function_invocation' => true,
                        'no_redundant_readonly_property' => true,
                        'static_lambda' => true,
                        'array_push' => true,
                        'ereg_to_preg' => true,
                        'modernize_strpos' => true,
                        'pow_to_exponentiation' => true,
                        'random_api_migration' => true,
                        'set_type_to_cast' => true,
                        'autoload_optimization' => true,
                        'condition_order' => true,
                    ],
                    'fixers' => ['condition_order' => false],
                ],
                'style' => ['mode' => 'no_regression', 'tool' => GateToolRegistry::DEFAULT],
                'static_analysis' => ['mode' => 'no_regression', 'tool' => GateToolRegistry::DEFAULT],
                'coverage' => ['mode' => 'no_regression', 'floor' => 85.0, 'tool' => GateToolRegistry::DEFAULT],
                'file_size' => ['mode' => 'no_regression', 'max_lines' => 1000],
                'complexity' => ['mode' => 'no_regression', 'block_at' => 50, 'warn_at' => 20],
                'parallel' => [
                    'enabled' => true,
                    'max_processes' => self::DEFAULT_MAX_PROCESSES,
                ],
            ],
            'results' => [
                'security' => ['advisories' => 0, 'findings' => 0, 'critical' => 0],
                'style' => ['violations' => 0],
                'static_analysis' => ['errors' => 0],
                'coverage' => ['percentage' => 85.0],
                'duplication' => ['percentage' => 0.0, 'clones' => 0],
                'file_size' => ['over_limit' => 0],
                'complexity' => ['max_ccn' => 0, 'violations' => 0, 'warnings' => 0],
                'performance' => ['violations' => 0],
            ],
        ];
    }

    /**
     * Migrates the flat v1 layout to v2. Unknown legacy gate keys are retained
     * as configuration so custom settings are never discarded.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (is_array($data['config'] ?? null) && is_array($data['results'] ?? null)) {
            $data['schema'] = Baseline::SCHEMA;

            return $data;
        }

        $normalized = ['schema' => Baseline::SCHEMA, 'config' => [], 'results' => []];

        foreach ($data as $section => $values) {
            if (in_array($section, ['schema', 'created_at', 'updated_at'], true)) {
                continue;
            }

            if (!is_array($values)) {
                $normalized['config'][$section] = $values;

                continue;
            }

            $resultKeys = self::RESULT_KEYS[$section] ?? [];
            foreach ($values as $key => $value) {
                $group = is_string($key) && in_array($key, $resultKeys, true) ? 'results' : 'config';
                $normalized[$group][$section][$key] = $value;
            }
        }

        foreach (['created_at', 'updated_at'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $normalized[$key] = $data[$key];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function mergeDefaults(array $existing, array $defaults): array
    {
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $existing)) {
                $existing[$key] = $default;

                continue;
            }

            if (is_array($default) && is_array($existing[$key])) {
                $existing[$key] = self::mergeDefaults($existing[$key], $default);
            }
        }

        return $existing;
    }
}
