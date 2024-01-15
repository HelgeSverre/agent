<?php

namespace App\Agent;

use HelgeSverre\Brain\Facades\Brain;
use Illuminate\Support\Arr;

class SimpleAgent
{
    protected bool $isTaskCompleted = false;

    protected array $intermediateSteps = [];

    protected int $currentIteration = 0;

    protected CallbackHandler $callbacks;

    /**
     * @param  array|Tool[]  $tools
     */
    public function __construct(
        protected array $tools = [],
        protected ?string $goal = null,
        protected int $maxIterations = 10,
    ) {
    }

    public function run(string $task)
    {
        while (! $this->isTaskCompleted) {
            $this->currentIteration++;

            if ($this->currentIteration > $this->maxIterations) {
                return "Max iterations reached: {$this->maxIterations}";
            }

            $nextStep = $this->decideNextStep($task);

            // TODO: parse into class

            $this->recordStep('thought', $nextStep['thought'] ?? '');
            $this->recordStep('action', Arr::only($nextStep, ['action', 'action_input']));

            if ($nextStep['action'] === 'final_answer') {
                $this->isTaskCompleted = true;

                $this->checkIfDone($task);

                // TODO: should return status = "completed" or "not completed"
                // if not completed, record step and continue  should return the next step to be done

                return $nextStep['action_input'];
            }

            // TODO: call tool

            $observation = $this->executeTool($nextStep['action'], $nextStep['action_input']);

            $this->recordStep('observation', $observation);
        }
    }

    protected function executeTool($action, $input): ?string
    {
        $toolName = $action['action'];
        $input = $action['action_input'];

        /** @var Tool $tool */
        $tool = collect($this->tools)->first(fn (Tool $tool) => $tool->name() === $toolName);

        return $tool->execute($input);
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

        return Brain::temperature(0.5)->slow()->json($prompt);
    }

    protected function recordStep(string $type, mixed $content)
    {
        $this->intermediateSteps[] = ['type' => $type, 'content' => $content];
    }
}
