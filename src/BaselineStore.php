<?php

declare(strict_types=1);

namespace B7S\Catraca;

use JsonException;
use RuntimeException;

use function fclose;
use function fflush;
use function file_exists;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rewind;
use function stream_get_contents;

final class BaselineStore
{
    /** @var array<string, mixed>|null */
    private ?array $cachedData = null;

    private bool $cacheLoaded = false;

    public function __construct(
        private readonly string $path,
    ) {}

    public function exists(): bool
    {
        return file_exists($this->path);
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
        if (!$this->exists()) {
            return null;
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }

            $data = $this->decode(stream_get_contents($handle));
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $this->cachedData = $data;

        return $this->cachedData;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    public function write(array $data): void
    {
        $this->update(static fn(): array => $data);
    }

    /**
     * @param  callable(array<string, mixed>|null): array<string, mixed>  $updater
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function update(callable $updater): array
    {
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open baseline for writing: ' . $this->path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock baseline for writing: ' . $this->path);
            }

            rewind($handle);
            $data = $updater($this->decode(stream_get_contents($handle)));
            $this->writeToHandle($handle, $data);
            flock($handle, LOCK_UN);
            $this->cachedData = $data;
            $this->cacheLoaded = true;

            return $data;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string|false $content): ?array
    {
        if (!is_string($content) || $content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    private function writeToHandle($handle, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) {
            throw new RuntimeException('Unable to write baseline: ' . $this->path);
        }
    }
}
