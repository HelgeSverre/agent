<?php

namespace App\Agent;

use Closure;

class Hooks
{
    public function __construct(protected array $listeners = [])
    {
    }

    public function on(string $event, Closure $callback): self
    {
        $this->listeners[$event] = $callback;

        return $this;
    }

    public function onAny(Closure $callback): self
    {
        $this->listeners['*'] = $callback;

        return $this;
    }

    public function trigger(string $event, ...$args): void
    {
        if (isset($this->listeners['*'])) {
            ($this->listeners['*'])($event, ...$args);
        }

        if (isset($this->listeners[$event])) {
            ($this->listeners[$event])(...$args);
        }
    }
}
