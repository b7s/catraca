<?php

declare(strict_types=1);

namespace B7S\Catraca\Command;

use B7S\Catraca\Catraca;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ProjectCommand extends Command implements SignalableCommandInterface
{
    private ?Catraca $activeCatraca = null;

    use CommandHelper;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runForProject($input, $output, fn(
            string $projectRoot,
            Catraca $catraca,
        ): int => $this->executeForProject($input, $output, $projectRoot, $catraca));
    }

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return [2, 15];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->activeCatraca?->cancel();

        return Command::FAILURE;
    }

    protected function setActiveCatraca(?Catraca $catraca): void
    {
        $this->activeCatraca = $catraca;
    }

    abstract protected function executeForProject(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        Catraca $catraca,
    ): int;
}
