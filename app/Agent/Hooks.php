<?php

namespace App\Agent;

use Closure;

class Hooks
{
    /**
     * @var array <string, Closure>
     */
    protected array $listeners = [];

    public function __construct(array $listeners = [])
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
        ($this->listeners['*'])($event, ...$args);

        if (isset($this->listeners[$event])) {
            ($this->listeners[$event])(...$args);
        }
    }
}
