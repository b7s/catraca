<?php

declare(strict_types=1);

namespace B7S\Catraca;

use DateTimeImmutable;
use DateTimeInterface;

use function array_filter;
use function array_merge;
use function array_replace_recursive;
use function hash;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function max;
use function min;
use function serialize;

class Baseline
{
    private const string FILENAME = 'catraca_baseline.json';

    private const array DEFAULT_SOURCE_DIRS = ['src', 'app', 'lib'];

    private const int MAX_PROCESSES_LIMIT = 128;

    public const string SCHEMA = 'catraca/v2';

    public readonly string $projectRoot;

    private BaselineStore $store;

    /** @var array<string, mixed>|null */
    private ?array $cachedData = null;

    private bool $cacheLoaded = false;

    public function __construct(
        string $projectRoot,
        private ?bool $parallelOverride = null,
        private string $profile = 'default',
        private ?string $changedFrom = null,
        private ?int $timeoutOverride = null,
    ) {
        $this->projectRoot = $projectRoot;
        $this->store = new BaselineStore($this->getPath());
    }

    public function getPath(): string
    {
        return $this->projectRoot . '/' . self::FILENAME;
    }

    public function exists(): bool
    {
        return $this->store->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if ($this->cacheLoaded) {
            return $this->cachedData;
        }

        $this->cacheLoaded = true;
        $data = $this->store->read();
        $this->cachedData = $data === null ? null : BaselineSchema::normalize($this->normalizeArray($data));

        return $this->cachedData;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function write(array $data): void
    {
        $data = $this->prepareForWrite(BaselineSchema::normalize($data));
        $this->store->write($data);
        $this->cachedData = $data;
        $this->cacheLoaded = true;
    }

    /**
     * Updates measured values without replacing configuration or other gate
     * results, even when multiple init processes run concurrently.
     *
     * @param  array<string, array<string, mixed>>  $results
     */
    public function updateResults(array $results): void
    {
        $data = $this->store->update(function (?array $stored) use ($results): array {
            $data = $stored === null ? $this->defaults() : BaselineSchema::normalize($stored);
            $target = $this->profile === 'default' ? 'results' : 'profiles';

            foreach ($results as $gate => $current) {
                if ($target === 'results') {
                    $existing = $data['results'][$gate] ?? [];
                    $data['results'][$gate] = array_merge(is_array($existing) ? $existing : [], $current);

                    continue;
                }

                $existing = $data['profiles'][$this->profile]['results'][$gate] ?? [];
                $data['profiles'][$this->profile]['results'][$gate] = array_merge(
                    is_array($existing) ? $existing : [],
                    $current,
                );
            }

            return $this->prepareForWrite($data);
        });

        $this->cachedData = $data;
        $this->cacheLoaded = true;
    }

    public function init(): void
    {
        $data = $this->store->update(function (?array $stored): array {
            $defaults = $this->defaults();

            if ($stored === null) {
                $defaults['created_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

                return $this->prepareForWrite($defaults);
            }

            return $this->prepareForWrite(BaselineSchema::mergeDefaults(BaselineSchema::normalize($stored), $defaults));
        });

        $this->cachedData = $data;
        $this->cacheLoaded = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return BaselineSchema::defaults();
    }

    /**
     * @return array<int, string>
     */
    public function getSourceDirs(): array
    {
        $dirs = $this->getConfig('source_dirs', 'paths', null);
        if (is_array($dirs) && $dirs !== []) {
            return $dirs;
        }

        return self::DEFAULT_SOURCE_DIRS;
    }

    /** @return array<int, string> */
    public function getExcludePaths(): array
    {
        $paths = $this->getConfig('source_dirs', 'exclude', ['vendor', '.git', 'node_modules']);

        return is_array($paths) ? array_values($paths) : ['vendor', '.git', 'node_modules'];
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getChangedFrom(): ?string
    {
        return $this->changedFrom;
    }

    public function getPolicy(string $key, mixed $default = null): mixed
    {
        return $this->getConfig('policy', $key, $default);
    }

    public function getGateTimeout(string $gate): ?float
    {
        if ($this->timeoutOverride !== null) {
            return (float) $this->timeoutOverride;
        }

        $gateTimeout = $this->getConfig($gate, 'timeout_seconds', null);
        if (is_int($gateTimeout) && $gateTimeout > 0) {
            return (float) $gateTimeout;
        }

        $timeout = $this->getConfig('process', 'timeout_seconds', 1200);

        return is_int($timeout) && $timeout > 0 ? (float) $timeout : null;
    }

    public function getConfigHash(): string
    {
        $data = $this->read() ?? $this->defaults();
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        if ($this->profile !== 'default') {
            $profileConfig = $data['profiles'][$this->profile]['config'] ?? [];
            if (is_array($profileConfig)) {
                $config = array_replace_recursive($config, $profileConfig);
            }
        }

        return hash('sha256', serialize([
            'profile' => $this->profile,
            'config' => $config,
        ]));
    }

    public function isParallelEnabled(): bool
    {
        if ($this->parallelOverride !== null) {
            return $this->parallelOverride;
        }

        $enabled = $this->getConfig('parallel', 'enabled', null);

        return is_bool($enabled) ? $enabled : true;
    }

    public function getMaxProcesses(): int
    {
        $maxProcesses = $this->getConfig('parallel', 'max_processes', BaselineSchema::DEFAULT_MAX_PROCESSES);
        if (!is_int($maxProcesses) || $maxProcesses < 1) {
            return BaselineSchema::DEFAULT_MAX_PROCESSES;
        }

        return min($maxProcesses, self::MAX_PROCESSES_LIMIT);
    }

    public function getGateTool(string $gate): string
    {
        $tool = $this->getConfig($gate, 'tool', GateToolRegistry::DEFAULT);

        return is_string($tool) ? $tool : GateToolRegistry::DEFAULT;
    }

    public function isMagoEnabled(string $capability): bool
    {
        $enabled = $this->getConfig('mago', 'enabled', true);
        $capabilityEnabled = $this->getConfig('mago', $capability, true);

        return $enabled === true && $capabilityEnabled === true;
    }

    public function getMagoThreads(): int
    {
        $configured = $this->getConfig('mago', 'threads', 0);
        if (is_int($configured) && $configured > 0) {
            return min($configured, self::MAX_PROCESSES_LIMIT);
        }

        return max(1, (int) ($this->getMaxProcesses() / 3));
    }

    public function getMagoMinimumReportLevel(): string
    {
        $level = $this->getConfig('mago', 'minimum_report_level', 'warning');

        return is_string($level) && in_array($level, ['help', 'note', 'warning', 'error'], true) ? $level : 'warning';
    }

    public function getConfig(string $section, string $key, mixed $default = null): mixed
    {
        return $this->getFromGroup('config', $section, $key, $default);
    }

    public function getResult(string $gate, string $key, mixed $default = null): mixed
    {
        return $this->getFromGroup('results', $gate, $key, $default);
    }

    /**
     * Backward-compatible accessor. New code should select the group explicitly.
     */
    public function get(string $section, string $key, mixed $default = null): mixed
    {
        $config = $this->getConfig($section, $key, null);
        if ($config !== null) {
            return $config;
        }

        return $this->getResult($section, $key, $default);
    }

    private function getFromGroup(string $group, string $section, string $key, mixed $default): mixed
    {
        $data = $this->read();
        if ($data === null) {
            return $default;
        }

        $groupData = is_array($data[$group] ?? null) ? $data[$group] : [];
        if ($this->profile !== 'default') {
            $profileData = $data['profiles'][$this->profile][$group] ?? [];
            if (is_array($profileData)) {
                $groupData = $group === 'config' ? array_replace_recursive($groupData, $profileData) : $profileData;
            }
        }

        if (!is_array($groupData[$section] ?? null)) {
            return $default;
        }

        return $groupData[$section][$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareForWrite(array $data): array
    {
        $data['schema'] = self::SCHEMA;
        $data['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(array $data): array
    {
        return array_filter($data, static fn(mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
