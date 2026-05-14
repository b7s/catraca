<?php

namespace B7S\Catraca\Command;

use DirectoryIterator;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

use function is_subclass_of;

class CommandDiscovery
{
    /**
     * Discover all command classes in a given directory.
     *
     * @return array<int, class-string<Command>>
     */
    public function discover(string $directory, string $namespace): array
    {
        $commands = [];

        if (! is_dir($directory)) {
            return $commands;
        }

        foreach (new DirectoryIterator($directory) as $file) {
            if ($file->isDot() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace.'\\'.$file->getBasename('.php');

            if (! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Command::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $attributes = $reflection->getAttributes(AsCommand::class);
            if ($attributes === []) {
                continue;
            }

            $commands[] = $class;
        }

        return $commands;
    }
}
