<?php

namespace App\Agent;

use App\Agent\Tool\Tool;
use Exception;

class Agent
{
    protected bool $isTaskCompleted = false;

    protected array $intermediateSteps = [];

    protected int $maxIntermediateSteps = 10;

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
                ];
            }

            $this->toolsSchema[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $parameters,
                    'required' => array_values(array_map(fn ($arg) => $arg->name, array_filter($tool->arguments(), fn ($arg) => ! $arg->nullable))),
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
            'object' => 'object',
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

            if (isset($nextStep['function_call'])) {
                $toolName = $nextStep['function_call']['name'];
                $toolInput = $nextStep['function_call']['arguments'] ?? [];

                $this->hooks?->trigger('action', ['action' => $toolName, 'action_input' => $toolInput]);
                $this->recordStep('action', ['action' => $toolName, 'action_input' => $toolInput]);

                // TODO: might be better to call it "reasoning" when its related to "why this next step", instead of allowing "think about it" as a separate action to take.
                if ($toolInput['thought'] ?? false) {
                    $this->hooks?->trigger('thought', $toolInput['thought'] ?? '');
                    $this->recordStep('thought', $toolInput['thought'] ?? '');
                }

                if ($toolName === 'final_answer') {
                    $this->isTaskCompleted = true;
                    $evaluation = $this->evaluateTaskCompletion($task);

                    if ($evaluation && isset($evaluation['status']) && $evaluation['status'] === 'completed') {
                        $this->hooks?->trigger('final_answer', $toolInput['answer'] ?? $toolInput);

                        return $toolInput['answer'] ?? $toolInput;
                    } else {
                        $feedback = $evaluation['feedback'] ?? 'Failed to evaluate task completion';
                        $this->recordStep('observation', $feedback);
                        $this->isTaskCompleted = false; // Reset so agent continues

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

        $response = LLM::json($prompt);
        $this->hooks?->trigger('evaluation', $response);

        return $response;
    }

    protected function trimIntermediateSteps(): void
    {
        if (count($this->intermediateSteps) > $this->maxIntermediateSteps) {
            $this->intermediateSteps = array_slice($this->intermediateSteps, -$this->maxIntermediateSteps);
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
        $result = LLM::functionCall([
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
                    'required' => ['answer', 'thought'],
                ],
            ],
        ])
            ->get($prompt);

        // Debug logging
        if (isset($result['error'])) {
            $this->hooks?->trigger('observation', 'Function call error: '.$result['error']);
        }

        return $result;
    }

    protected function recordStep(string $type, mixed $content)
    {
        $this->intermediateSteps[] = ['type' => $type, 'content' => $content];
    }
}
