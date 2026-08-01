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
use function strtolower;

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
            $data = $stored === null ? $this->defaults() : BaselineSchema::normalize($this->normalizeArray($stored));
            foreach ($results as $gate => $current) {
                $data = $this->withGateResult($data, $gate, $current);
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

            return $this->prepareForWrite(BaselineSchema::mergeDefaults(
                BaselineSchema::normalize($this->normalizeArray($stored)),
                $defaults,
            ));
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
        $resolved = $this->stringList($dirs);

        return $resolved !== [] ? $resolved : self::DEFAULT_SOURCE_DIRS;
    }

    /** @return array<int, string> */
    public function getExcludePaths(): array
    {
        $paths = $this->getConfig('source_dirs', 'exclude', ['vendor', '.git', 'node_modules']);

        return $this->stringList($paths, ['vendor', '.git', 'node_modules']);
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

        $gateTimeout = $this->getIntConfig($gate, 'timeout_seconds', 0);
        if ($gateTimeout > 0) {
            return (float) $gateTimeout;
        }

        $timeout = $this->getIntConfig('process', 'timeout_seconds', 1200);

        return $timeout > 0 ? (float) $timeout : null;
    }

    public function getConfigHash(): string
    {
        $data = $this->read() ?? $this->defaults();
        /** @var array<string, mixed> $config */
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        if ($this->profile !== 'default') {
            /** @var mixed $profileConfigRaw */
            $profileConfigRaw = $data['profiles'][$this->profile]['config'] ?? [];
            $profileConfig = is_array($profileConfigRaw) ? $profileConfigRaw : [];
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

        $enabled = $this->getBoolConfig('parallel', 'enabled', true);

        return $enabled;
    }

    public function getMaxProcesses(): int
    {
        $maxProcesses = $this->getIntConfig('parallel', 'max_processes', BaselineSchema::DEFAULT_MAX_PROCESSES);
        if ($maxProcesses < 1) {
            return BaselineSchema::DEFAULT_MAX_PROCESSES;
        }

        return min($maxProcesses, self::MAX_PROCESSES_LIMIT);
    }

    public function getGateTool(string $gate): string
    {
        $tool = $this->getStringConfig('tools', GateToolRegistry::operation($gate), GateToolRegistry::DEFAULT);

        return strtolower($tool);
    }

    public function getMagoThreads(): int
    {
        $configured = $this->getIntConfig('tools', 'options.mago.threads', 0);
        if ($configured > 0) {
            return min($configured, self::MAX_PROCESSES_LIMIT);
        }

        return max(1, (int) ($this->getMaxProcesses() / 3));
    }

    public function getMagoMinimumReportLevel(): string
    {
        $level = $this->getStringConfig('tools', 'options.mago.minimum_report_level', 'error');

        return in_array($level, ['help', 'note', 'warning', 'error'], true) ? $level : 'error';
    }

    public function getMagoMinimumVersion(): string
    {
        $version = $this->getStringConfig(
            'tools',
            'options.mago.minimum_version',
            GateToolRegistry::MINIMUM_MAGO_VERSION,
        );

        return is_string($version) && MagoVersionChecker::isValid($version)
            ? $version
            : GateToolRegistry::MINIMUM_MAGO_VERSION;
    }

    private function getMagoOption(string $key, mixed $default): mixed
    {
        /** @var array<string, mixed> $options */
        $options = $this->getArrayConfig('tools', 'options', []);
        if (!is_array($options)) {
            return $default;
        }

        /** @var mixed $mago */
        $mago = $options['mago'] ?? null;
        if (!is_array($mago)) {
            return $default;
        }

        return $mago[$key] ?? $default;
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
     * Get config value as int
     */
    public function getIntConfig(string $section, string $key, int $default = 0): int
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getConfig($section, $key, $default);
        return is_int($value) ? $value : $default;
    }

    /**
     * Get config value as float
     */
    public function getFloatConfig(string $section, string $key, float $default = 0.0): float
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getConfig($section, $key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Get config value as bool
     */
    public function getBoolConfig(string $section, string $key, bool $default = false): bool
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getConfig($section, $key, $default);
        return is_bool($value) ? $value : $default;
    }

    /**
     * Get config value as string
     */
    public function getStringConfig(string $section, string $key, string $default = ''): string
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getConfig($section, $key, $default);
        return is_string($value) ? $value : $default;
    }

    /**
     * Get config value as array
     * @return array<string, mixed>
     */
    public function getArrayConfig(string $section, string $key, array $default = []): array
    {
        // @mago-ignore analysis:less-specific-return-statement
        /** @phpstan-ignore-next-line */
        $value = $this->getConfig($section, $key, $default);
        /** @phpstan-ignore-next-line */
        return is_array($value) ? $value : $default;
    }

    /**
     * Get result value as int
     */
    public function getIntResult(string $gate, string $key, int $default = 0): int
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getResult($gate, $key, $default);
        return is_int($value) ? $value : $default;
    }

    /**
     * Get result value as float
     */
    public function getFloatResult(string $gate, string $key, float $default = 0.0): float
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getResult($gate, $key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Get result value as string
     */
    public function getStringResult(string $gate, string $key, string $default = ''): string
    {
        /** @phpstan-ignore-next-line */
        $value = $this->getResult($gate, $key, $default);
        return is_string($value) ? $value : $default;
    }

    /**
     * Get result value as array
     * @return array<string, mixed>
     */
    public function getArrayResult(string $gate, string $key, array $default = []): array
    {
        // @mago-ignore analysis:less-specific-return-statement
        /** @phpstan-ignore-next-line */
        $value = $this->getResult($gate, $key, $default);
        /** @phpstan-ignore-next-line */
        return is_array($value) ? $value : $default;
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

    /**
     * @param  string  $group
     * @param  string  $section
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
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
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function withGateResult(array $data, string $gate, array $current): array
    {
        if ($this->profile === 'default') {
            $resultGroup = $this->normalizeArray(is_array($data['results'] ?? null) ? $data['results'] : []);
            $this->mergeGateResult($resultGroup, $gate, $current);
            $data['results'] = $resultGroup;

            return $data;
        }

        $profiles = $this->normalizeArray(is_array($data['profiles'] ?? null) ? $data['profiles'] : []);
        $profile = $this->normalizeArray(is_array($profiles[$this->profile] ?? null) ? $profiles[$this->profile] : []);
        $resultGroup = $this->normalizeArray(is_array($profile['results'] ?? null) ? $profile['results'] : []);
        $this->mergeGateResult($resultGroup, $gate, $current);
        $profile['results'] = $resultGroup;
        $profiles[$this->profile] = $profile;
        $data['profiles'] = $profiles;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $resultGroup
     */
    private function mergeGateResult(array &$resultGroup, string $gate, array $current): void
    {
        $existing = $this->normalizeArray(is_array($resultGroup[$gate] ?? null) ? $resultGroup[$gate] : []);
        $resultGroup[$gate] = array_merge($existing, $current);
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function stringList(mixed $value, array $fallback = []): array
    {
        if (!is_array($value)) {
            return $fallback;
        }

        /** @var array<int, string> $strings */
        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings !== [] ? $strings : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(array $data): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
