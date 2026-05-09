<?php

namespace B7S\Catraca\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait CommandHelper
{
    private function resolveProjectRoot(InputInterface $input, OutputInterface $output): ?string
    {
        $pathOption = $input->getOption('path');
        $rawPath = is_string($pathOption) ? $pathOption : (string) getcwd();
        $projectRoot = realpath($rawPath);
        if ($projectRoot === false || ! is_dir($projectRoot)) {
            $output->writeln(sprintf('<error>Directory not found: %s</error>', $rawPath));

            return null;
        }

        return $projectRoot;
    }

    private function resolveFormat(InputInterface $input, OutputInterface $output): string
    {
        /** @var string $format */
        $format = $input->getOption('format');

        return $format;
    }

    private function isPlainOutput(InputInterface $input, OutputInterface $output): bool
    {
        return $input->getOption('plain') || ! $output->isDecorated();
    }
}
