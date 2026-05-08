<?php

namespace B7S\Catraca;

use B7S\Catraca\Enum\ActionType;

readonly class Action
{
    /**
     * @param  string[]  $files
     */
    public function __construct(
        public ActionType $type,
        public string $message,
        public array $files = [],
        public int $priority = 0,
    ) {}

    /**
     * @return array{type: string, priority: int, message: string, files: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'priority' => $this->priority,
            'message' => $this->message,
            'files' => array_values($this->files),
        ];
    }
}
