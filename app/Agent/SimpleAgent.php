<?php

namespace App\Agent;

use App\Agent\Tool\Tool;
use HelgeSverre\Brain\Facades\Brain;
use Illuminate\Support\Arr;

class SimpleAgent
{
    protected bool $isTaskCompleted = false;

    protected array $intermediateSteps = [];

    protected int $currentIteration = 0;

    /**
     * @param  array|Tool[]  $tools
     */
    public function __construct(
        protected array $tools = [],
        protected ?string $goal = null,
        protected int $maxIterations = 10,
        protected ?Hooks $hooks = null,
    ) {
    }

    public function run(string $task)
    {
        $this->hooks?->trigger('start', $task);

        while (! $this->isTaskCompleted) {
            $this->currentIteration++;

            $this->hooks?->trigger('iteration', $this->currentIteration);

            if ($this->currentIteration > $this->maxIterations) {
                $this->hooks?->trigger('max_iteration', $this->currentIteration, $this->maxIterations);

                return "Max iterations reached: {$this->maxIterations}";
            }

            $nextStep = $this->decideNextStep($task);
            $this->hooks?->trigger('next_step', $nextStep);

            $this->hooks?->trigger('thought', $nextStep['thought'] ?? '');
            $this->recordStep('thought', $nextStep['thought'] ?? '');

            $this->hooks?->trigger('action', Arr::only($nextStep ?? [], ['action', 'action_input']));
            $this->recordStep('action', Arr::only($nextStep ?? [], ['action', 'action_input']));

            if ($nextStep['action'] === 'final_answer') {
                $this->isTaskCompleted = true;

                // TODO: Configurable
                // $this->checkIfDone($task);

                // TODO: should return status = "completed" or "not completed"
                // if not completed, record step and continue  should return the next step to be done

                $this->hooks?->trigger('final_answer', $nextStep['action_input']);

                return $nextStep['action_input'];
            }

            $observation = $this->executeTool($nextStep['action'], $nextStep['action_input']);
            $this->hooks?->trigger('observation', $observation);

            $this->recordStep('observation', $observation);
        }
    }

    protected function executeTool($toolName, $toolInput): ?string
    {

        /** @var Tool $tool */
        $tool = collect($this->tools)->first(fn (Tool $tool) => $tool->name() === $toolName);

        // TODO: Handle exception
        $this->hooks?->trigger('tool_execution', $toolName, $toolInput);

        return $tool->execute($toolInput);
    }

    protected function checkIfDone(string $task)
    {
        $prompt = Prompt::make(
            task: $task,
            goal: $this->goal,
            tools: $this->tools,
            intermediateSteps: $this->intermediateSteps,
        )->evaluateTaskCompletion();

        $response = Brain::json($prompt);

        // TODO: maybe it makes sense to return this data:
        //   {"status": "completed", "feedback": "The task is completed !", "tasks": []}
        //   or
        //   {"status": "not completed", "feedback": "not all tasks have been completed", "tasks": ["task 1","task 2"]}

        return $response;
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

        // TODO: Parse, if parse failure, recover with LLM call, if total failure, throw exception

        return Brain::temperature(0.5)->slow()->json($prompt);
    }

    protected function recordStep(string $type, mixed $content)
    {
        $this->intermediateSteps[] = ['type' => $type, 'content' => $content];
    }
}
