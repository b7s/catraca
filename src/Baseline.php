<?php

namespace B7S\Catraca;

class Baseline
{
    private const FILENAME = 'baseline.json';
    private const SCHEMA = 'catraca/v1';

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function getPath(): string
    {
        return $this->projectRoot . '/' . self::FILENAME;
    }

    public function exists(): bool
    {
        return file_exists($this->getPath());
    }

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
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    public function write(array $data): void
    {
        $data['schema'] = self::SCHEMA;
        $data['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->getPath(), $json);
    }

    public function init(): void
    {
        $this->write([
            'schema' => self::SCHEMA,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'security' => ['advisories' => null],
            'style' => ['violations' => 0],
            'static_analysis' => ['errors' => 0],
            'coverage' => ['percentage' => 0.0],
            'duplication' => ['percentage' => 100.0, 'clones' => []],
            'file_size' => ['max_lines' => 0],
            'complexity' => ['max_ccn' => 0],
        ]);
    }

    public function get(string $gate, string $key, mixed $default = null): mixed
    {
        $data = $this->read();
        if ($data === null) {
            return $default;
        }

        return $data[$gate][$key] ?? $default;
    }
}
