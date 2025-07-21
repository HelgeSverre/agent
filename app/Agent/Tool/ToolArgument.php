<?php

namespace App\Agent\Tool;

readonly class ToolArgument
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public ?string $description = null,
    ) {}
}
