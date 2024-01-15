<?php

namespace App\Tools;

use App\Agent\Tool;
use Closure;

class SimpleTool implements Tool
{
    public function __construct(
        protected string $name,
        protected string $description,
        protected Closure $callback
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function execute(...$args): string
    {
        return $this->callback->call($this, $args);
    }
}
