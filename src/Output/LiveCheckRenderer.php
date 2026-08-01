<?php

declare(strict_types=1);

namespace B7S\Catraca\Output;

use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\GateRunObserverInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function array_map;
use function count;
use function rtrim;
use function strtoupper;

final class LiveCheckRenderer implements GateRunObserverInterface
{
    private const array SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** @var array<int, string> */
    private array $states;

    /** @var array<int, GateResult> */
    private array $results = [];

    private int $frame = 0;

    /**
     * @param  array<int, string>  $labels
     */
    public function __construct(
        private readonly ConsoleSectionOutput $section,
        private readonly array $labels,
    ) {
        $this->states = array_map(static fn(): string => 'queued', $labels);
    }

    public function start(): void
    {
        $this->render();
    }

    public function started(int $index): void
    {
        $this->states[$index] = 'running';
        $this->render();
    }

    public function tick(): void
    {
        $this->frame = ($this->frame + 1) % count(self::SPINNER_FRAMES);
        $this->render();
    }

    public function finished(int $index, GateResult $result): void
    {
        $this->states[$index] = 'finished';
        $this->results[$index] = $result;
        $this->render();
    }

    private function render(): void
    {
        $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true, $this->section->getFormatter());
        $buffer->writeln('<info>CATRACA — PHP Quality Gate Report</info>');

        $table = new Table($buffer);
        $table->setStyle('box')->setHeaders(['', 'Gate', 'Status', 'Description'])->setRows($this->rows())->render();

        $this->section->overwrite(rtrim($buffer->fetch(), "\n"));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->labels as $index => $label) {
            if (isset($this->results[$index])) {
                $rows[] = $this->finishedRow($label, $this->results[$index]);

                continue;
            }

            if (($this->states[$index] ?? 'queued') === 'running') {
                $rows[] = [
                    '<comment>' . self::SPINNER_FRAMES[$this->frame] . '</comment>',
                    $label,
                    '<comment>RUNNING</comment>',
                    'Running…',
                ];

                continue;
            }

            $rows[] = ['·', $label, '<fg=gray>QUEUED</>', 'Waiting for a worker…'];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function finishedRow(string $label, GateResult $result): array
    {
        [$icon, $style] = match ($result->status) {
            Status::Pass => ['✔', 'info'],
            Status::Fail => ['🚫', 'error'],
            Status::Warn => ['⚠', 'comment'],
            Status::Skip => ['—', 'fg=gray'],
            Status::Cancelled => ['�', 'error'],
        };

        return [
            '<' . $style . '>' . $icon . '</>',
            $label,
            '<' . $style . '>' . strtoupper($result->status->value) . '</>',
            $result->message,
        ];
    }
}
