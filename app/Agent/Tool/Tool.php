<?php

namespace App\Agent\Tool;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use DateTime;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Arr;
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
            $attribute = $param->getAttributes()[0] ?? null;

            $arguments[] = new ToolArgument(
                name: $param->getName(),
                type: $param->getType()?->getName() ?? 'string',
                nullable: $param->allowsNull(),
                description: $attribute?->newInstance()->description ?? null
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
    public function execute($arguments)
    {
        $this->validate();

        $args = $this->arguments();

        foreach ($args as $arg) {

            $value = $arguments[$arg->name] ?? null;

            if (! $value) {
                continue;
            }

            $arguments[$arg->name] = match ($arg->type) {
                Carbon::class => Carbon::parse($value),
                CarbonImmutable::class => CarbonImmutable::parse($value),
                DateTime::class => Carbon::parse($value)->toDateTime(),
                DateTimeImmutable::class => CarbonImmutable::parse($value)->toDateTimeImmutable(),
                default => $value
            };
        }

        // TODO: remove extra arguments that are not defined in the tool
        $validArgs = Arr::only($arguments, collect($args)->map(fn ($arg) => $arg->name)->toArray());

        return call_user_func_array([$this, 'run'], $validArgs);
    }
}
