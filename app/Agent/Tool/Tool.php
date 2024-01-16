<?php

namespace App\Agent\Tool;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionFunction;

abstract class Tool
{
    public static function fromClosure(string $name, string $description, Closure $closure): static
    {
        return new class($name, $description, $closure) extends Tool
        {
            public function __construct(
                protected string $name,
                protected string $description,
                protected Closure $closure,
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

            public function arguments(): array
            {
                $reflector = new ReflectionFunction($this->closure);
                $arguments = [];

                foreach ($reflector->getParameters() as $param) {
                    $arguments[] = new ToolArgument(
                        name: $param->getName(),
                        type: $param->getType()->getName(),
                        nullable: $param->allowsNull(),
                        description: $param->getAttributes()[0]?->newInstance()->description
                    );
                }

                return $arguments;

            }

            public function run(...$arguments): mixed
            {
                return $this->closure->call($this, ...$arguments);
            }
        };
    }

    public function name(): string
    {
        if (property_exists($this, 'name')) {
            return $this->name;
        }

        throw new Exception('Tool must implement a name method');
    }

    public function description(): string
    {
        if (property_exists($this, 'description')) {
            return $this->description;
        }

        throw new Exception('Tool must implement a description method');
    }

    /**
     * @return ToolArgument[]|array
     */
    public function arguments(): array
    {
        $this->validate();

        $reflector = new ReflectionClass($this);
        $arguments = [];

        foreach ($reflector->getMethod('run')->getParameters() as $param) {
            $arguments[] = new ToolArgument(
                name: $param->getName(),
                type: $param->getType()->getName(),
                nullable: $param->allowsNull(),
                description: $param->getAttributes()[0]?->newInstance()->description
            );
        }

        return $arguments;
    }

    /**
     * @throws Exception
     */
    protected function validate(): void
    {
        if (! method_exists($this, 'run')) {
            throw new Exception('Tool must implement a run method');
        }
    }

    /**
     * @throws Exception
     */
    public function __invoke(...$arguments)
    {
        $this->validate();

        return $this->run(...$arguments);
    }
}
