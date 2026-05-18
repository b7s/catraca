<?php

namespace B7S\Catraca\Command;

use B7S\Catraca\Baseline;
use B7S\Catraca\Catraca;
use B7S\Catraca\Fixer\AutoloadFixer;
use B7S\Catraca\Fixer\CodeStyleFixer;
use B7S\Catraca\Fixer\ConditionOrderFixer;
use B7S\Catraca\Fixer\FixerInterface;
use B7S\Catraca\Fixer\PerformanceFixer;
use B7S\Catraca\FixResult;
use B7S\Catraca\ToolResolver;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Run all quality gates and compare against baseline',
)]
class CheckCommand extends Command
{
    use CommandHelper;

    /** @var array<int, FixerInterface> */
    private array $fixers;

    public function __construct()
    {
        parent::__construct();
        $this->fixers = [
            new ConditionOrderFixer,
            new PerformanceFixer,
            new CodeStyleFixer,
            new AutoloadFixer,
        ];
    }

    protected function configure(): void
    {
        $this->addStandardOptions();
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Auto-fix issues if any gate fails');
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

        $catraca = new Catraca($projectRoot);
        $result = $catraca->check();

        $this->formatResult($input, $output, $result);

        if ($result->isPass() || ! $input->getOption('fix')) {
            return $result->isPass() ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln('');

        $baseline = new Baseline($projectRoot);
        $resolver = new ToolResolver($projectRoot);
        $fixResult = new FixResult;

        foreach ($this->fixers as $fixer) {
            $fixResult->add($fixer->fix($baseline, $resolver));
        }

        $this->formatFixResult($input, $output, $fixResult);

        $output->writeln('');

        $recheckResult = $catraca->check();

        $this->formatResult($input, $output, $recheckResult);

        return $recheckResult->isPass() ? Command::SUCCESS : Command::FAILURE;
    }
}
