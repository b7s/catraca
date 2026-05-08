<?php

namespace B7S\Catraca;

class Baseline
{
    private const FILENAME = 'baseline.json';
    private const SCHEMA = 'catraca/v1';
    public readonly string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    public function getPath(): string
    {
        return $this->projectRoot . '/' . self::FILENAME;
    }

    public function exists(): bool
    {
        return file_exists($this->getPath());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (!$this->exists()) {
            return null;
        }

        $content = file_get_contents($this->getPath());
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || $data === []) {
            return null;
        }

        return $this->normalizeArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function write(array $data): void
    {
        $data['schema'] = self::SCHEMA;
        $data['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        file_put_contents($this->getPath(), $json . "\n");
    }

    public function init(): void
    {
        $this->write([
            'schema' => self::SCHEMA,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'security' => ['advisories' => null],
            'style' => ['violations' => 0],
            'static_analysis' => ['errors' => 0],
            'coverage' => ['percentage' => 85.0],
            'duplication' => ['percentage' => 2.0, 'clones' => []],
            'file_size' => ['max_lines' => 1000],
            'complexity' => ['max_ccn' => 0],
        ]);
    }

    public function get(string $gate, string $key, mixed $default = null): mixed
    {
        $data = $this->read();
        if ($data === null) {
            return $default;
        }

        if (!is_array($data[$gate] ?? null)) {
            return $default;
        }

        return $data[$gate][$key] ?? $default;
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
