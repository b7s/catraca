<?php

declare(strict_types=1);

namespace B7S\Catraca;

use Closure;
use Parallite\ParalliteClient;
use RuntimeException;
use Throwable;

use function array_keys;
use function array_values;
use function count;
use function ksort;
use function pcntl_waitpid;
use function posix_kill;
use function usleep;

use const WNOHANG;

final class ParallelTaskRunner
{
    private const int POLL_INTERVAL_MICROSECONDS = 80_000;

    private bool $cancelled = false;

    /** @var array<int, int> */
    private array $activePids = [];

    public function __construct(
        private int $maxProcesses,
    ) {}

    /**
     * @param  array<int, Closure(): mixed>  $tasks
     * @param  (Closure(int): void)|null  $onStarted
     * @param  (Closure(): void)|null  $onTick
     * @param  (Closure(int, mixed): void)|null  $onFinished
     * @return array<int, mixed>
     */
    public function run(
        array $tasks,
        ?Closure $onStarted = null,
        ?Closure $onTick = null,
        ?Closure $onFinished = null,
    ): array {
        if ($tasks === []) {
            return [];
        }

        $client = new ParalliteClient();
        /** @var array<int, array{pid: int}> $active */
        $active = [];
        $results = [];
        $nextIndex = 0;
        $taskCount = count($tasks);

        while ($nextIndex < $taskCount || $active !== []) {
            while ($nextIndex < $taskCount && !$this->cancelled && count($active) < $this->maxProcesses) {
                if ($onStarted !== null) {
                    $onStarted($nextIndex);
                }

                try {
                    $active[$nextIndex] = $client->async($tasks[$nextIndex]);
                    $this->activePids[$nextIndex] = $active[$nextIndex]['pid'];
                } catch (Throwable $exception) {
                    $result = new RuntimeException($exception->getMessage(), previous: $exception);
                    $results[$nextIndex] = $result;
                    if ($onFinished !== null) {
                        $onFinished($nextIndex, $result);
                    }
                }

                $nextIndex++;
            }

            if ($onTick !== null) {
                $onTick();
            }
            $completedAny = false;

            foreach (array_keys($active) as $index) {
                $future = $active[$index];
                if (!$this->isFinished($future['pid'])) {
                    continue;
                }

                // @mago-ignore analysis:possibly-invalid-argument
                try {
                    $result = $client->await($future);
                } catch (Throwable $exception) {
                    $result = new RuntimeException($exception->getMessage(), previous: $exception);
                }

                if ($this->cancelled) {
                    $result = new CancelledException('Cancelled by signal');
                }
                $results[$index] = $result;
                unset($active[$index]);
                if ($onFinished !== null) {
                    $onFinished($index, $result);
                }
                $completedAny = true;
                unset($this->activePids[$index]);
            }

            if ($active !== [] && !$completedAny) {
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
        }
        while ($nextIndex < $taskCount) {
            $result = new CancelledException('Cancelled before execution');
            $results[$nextIndex] = $result;
            if ($onFinished !== null) {
                $onFinished($nextIndex, $result);
            }
            $nextIndex++;
        }

        $this->activePids = [];

        ksort($results);

        return array_values($results);
    }

    private function isFinished(int $pid): bool
    {
        $waitedPid = pcntl_waitpid($pid, $status, WNOHANG);

        return $waitedPid !== 0;
    }

    public function cancel(): void
    {
        $this->cancelled = true;

        foreach ($this->activePids as $pid) {
            posix_kill($pid, 15);
        }
    }
}
