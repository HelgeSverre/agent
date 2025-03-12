<?php

namespace App\Agent;

use App\Agent\Tool\Tool;
use Exception;
use HelgeSverre\Brain\Facades\Brain;

class Agent
{
    protected bool $isTaskCompleted = false;

    protected array $intermediateSteps = [];

    protected int $currentIteration = 0;

    protected array $toolsSchema = [];

    /**
     * @param  array|Tool[]  $tools
     */
    public function __construct(
        protected array $tools = [],
        protected ?string $goal = null,
        protected int $maxIterations = 10,
        protected ?Hooks $hooks = null,
    ) {
        $this->prepareToolsSchema();
    }

    protected function prepareToolsSchema(): void
    {
        foreach ($this->tools as $tool) {
            $parameters = [];

            foreach ($tool->arguments() as $arg) {
                $parameters[$arg->name] = [
                    'type' => $this->mapPhpTypeToJsonSchema($arg->type),
                    'description' => $arg->description ?? '',
                    'required' => ! $arg->nullable,
                ];
            }

            $this->toolsSchema[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $parameters,
                    'required' => array_keys(array_filter($parameters, fn ($param) => $param['required'])),
                ],
            ];
        }
    }

    protected function mapPhpTypeToJsonSchema(string $phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'float', 'double' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string'
        };
    }

    public function run(string $task)
    {
        $this->hooks?->trigger('start', $task);

        while (! $this->isTaskCompleted) {
            $this->trimIntermediateSteps();
            $this->currentIteration++;
            $this->hooks?->trigger('iteration', $this->currentIteration);

            if ($this->currentIteration > $this->maxIterations) {
                $this->hooks?->trigger('max_iteration', $this->currentIteration, $this->maxIterations);

                return "Max iterations reached: {$this->maxIterations}";
            }

            $nextStep = $this->decideNextStep($task);
            $this->hooks?->trigger('next_step', $nextStep);

            if ($nextStep['thought'] ?? false) {
                $this->hooks?->trigger('thought', $nextStep['thought'] ?? '');
                $this->recordStep('thought', $nextStep['thought'] ?? '');
            }

            if (isset($nextStep['function_call'])) {
                $toolName = $nextStep['function_call']['name'];
                $toolInput = $nextStep['function_call']['arguments'] ?? [];

                $this->hooks?->trigger('action', ['action' => $toolName, 'action_input' => $toolInput]);
                $this->recordStep('action', ['action' => $toolName, 'action_input' => $toolInput]);

                if ($toolName === 'final_answer') {
                    $this->isTaskCompleted = true;
                    $evaluation = $this->evaluateTaskCompletion($task);

                    if ($evaluation['status'] === 'completed') {
                        $this->hooks?->trigger('final_answer', $toolInput['answer'] ?? $toolInput);

                        return $toolInput['answer'] ?? $toolInput;
                    } else {
                        $this->recordStep('observation', $evaluation['feedback']);

                        continue;
                    }
                }

                $observation = $this->executeTool($toolName, $toolInput);
                $this->hooks?->trigger('observation', $observation);
                $this->recordStep('observation', $observation);
            }
        }
    }

    protected function executeTool($toolName, $toolInput): ?string
    {
        /** @var Tool $tool */
        $tool = collect($this->tools)->first(fn (Tool $tool) => $tool->name() === $toolName);

        if ($tool === null) {
            return "Tool not found: {$toolName}";
        }

        $this->hooks?->trigger('tool_execution', $toolName, $toolInput);

        try {
            return $tool->execute($toolInput);
        } catch (Exception $e) {
            return "Error executing tool: {$e->getMessage()}";
        }
    }

    protected function evaluateTaskCompletion(string $task)
    {
        $prompt = Prompt::make(
            task: $task,
            goal: $this->goal,
            tools: $this->tools,
            intermediateSteps: $this->intermediateSteps,
        )->evaluateTaskCompletion();

        $response = Brain::json($prompt);
        $this->hooks?->trigger('evaluation', $response);

        return $response;
    }

    protected function trimIntermediateSteps(): void
    {
        if (count($this->intermediateSteps) > 5) {
            $this->intermediateSteps = array_slice($this->intermediateSteps, -5);
        }
    }

    protected function decideNextStep(string $task)
    {
        $prompt = Prompt::make(
            task: $task,
            goal: $this->goal,
            tools: $this->tools,
            intermediateSteps: $this->intermediateSteps,
        )->decideNextStep();

        $this->hooks?->trigger('prompt', $prompt);

        // Use function calling instead of JSON parsing
        return Brain::temperature(0.5)
            ->slow()
            ->functionCall([
                'functions' => $this->toolsSchema,
                'final_answer' => [
                    'name' => 'final_answer',
                    'description' => 'Complete the task and provide a final answer',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'answer' => [
                                'type' => 'string',
                                'description' => 'The final answer or response to the task',
                            ],
                            'thought' => [
                                'type' => 'string',
                                'description' => 'Your thinking process behind this answer',
                            ],
                        ],
                        'required' => ['answer'],
                    ],
                ],
            ])
            ->get($prompt);
    }

    protected function recordStep(string $type, mixed $content)
    {
        $this->intermediateSteps[] = ['type' => $type, 'content' => $content];
    }
}
