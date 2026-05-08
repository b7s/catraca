<?php

namespace B7S\RatchetBabysit;

use B7S\RatchetBabysit\Enum\ActionType;
use B7S\RatchetBabysit\Enum\Status;

readonly class Action
{
    /**
     * @param ActionType $type
     * @param string $message
     * @param string[] $files
     * @param int $priority Lower = higher priority
     */
    public function __construct(
        public ActionType $type,
        public string $message,
        public array $files = [],
        public int $priority = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'priority' => $this->priority,
            'message' => $this->message,
            'files' => $this->files,
        ];
    }
}
