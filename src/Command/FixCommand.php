<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\Baseline;
use B7S\Catraca\Fixer\AutoloadFixer;
use B7S\Catraca\Fixer\CodeStyleFixer;
use B7S\Catraca\Fixer\FixerInterface;
use B7S\Catraca\Fixer\PerformanceFixer;
use B7S\Catraca\FixResult;
use B7S\Catraca\ToolResolver;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'fix',
    description: 'Auto-fix code style and performance issues',
)]
class FixCommand extends Command
{
    use CommandHelper;

    /** @var array<int, FixerInterface> */
    private array $fixers;

    public function __construct()
    {
        parent::__construct();
        $this->fixers = [
            new PerformanceFixer,
            new CodeStyleFixer,
            new AutoloadFixer,
        ];
    }

    protected function configure(): void
    {
        $this->addStandardOptions();
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->resolveProjectRoot($input, $output);
        if ($projectRoot === null) {
            return Command::FAILURE;
        }

        $baseline = new Baseline($projectRoot);
        $resolver = new ToolResolver($projectRoot);
        $result = new FixResult;

        foreach ($this->fixers as $fixer) {
            $result->add($fixer->fix($baseline, $resolver));
        }

        $this->formatFixResult($input, $output, $result);

        return Command::SUCCESS;
    }
}
