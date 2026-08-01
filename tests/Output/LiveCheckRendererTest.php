<?php

declare(strict_types=1);

namespace B7S\Catraca\Tests\Output;

use B7S\Catraca\Enum\Status;
use B7S\Catraca\GateResult;
use B7S\Catraca\Output\LiveCheckRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function fclose;
use function fopen;

final class LiveCheckRendererTest extends TestCase
{
    /** @var resource */
    private $stream;

    /** @var array<int, ConsoleSectionOutput> */
    private array $sections = [];

    private ConsoleSectionOutput $section;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $this->stream = $stream;
        $this->section = new ConsoleSectionOutput(
            $this->stream,
            $this->sections,
            OutputInterface::VERBOSITY_NORMAL,
            true,
            new OutputFormatter(true),
        );
    }

    protected function tearDown(): void
    {
        fclose($this->stream);
    }

    public function test_updates_rows_from_queued_to_running_and_finished(): void
    {
        $renderer = new LiveCheckRenderer($this->section, ['Security Audit', 'Code Style']);

        $renderer->start();
        self::assertStringContainsString('QUEUED', $this->section->getContent());
        self::assertStringContainsString('Waiting for a worker', $this->section->getContent());

        $renderer->started(0);
        $renderer->tick();
        self::assertStringContainsString('RUNNING', $this->section->getContent());
        self::assertStringContainsString('Running', $this->section->getContent());

        $renderer->finished(0, $this->gateResult(Status::Pass, 'No findings'));
        self::assertStringContainsString('PASS', $this->section->getContent());
        self::assertStringContainsString('No findings', $this->section->getContent());
        self::assertStringContainsString('Code Style', $this->section->getContent());

        $renderer->started(1);
        $renderer->finished(1, $this->gateResult(Status::Fail, '2 violations'));
        self::assertStringContainsString('FAIL', $this->section->getContent());
        self::assertStringContainsString('2 violations', $this->section->getContent());
    }

    private function gateResult(Status $status, string $message): GateResult
    {
        return new GateResult(status: $status, name: 'test', label: 'Test', message: $message);
    }
}
