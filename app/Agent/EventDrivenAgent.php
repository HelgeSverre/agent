<?php

namespace App\Agent;

use HelgeSverre\Brain\Facades\Brain;
use Illuminate\Support\Arr;

class EventDrivenAgent
{
    protected bool $isTaskCompleted = false;

    protected array $intermediateSteps = [];

    protected int $currentIteration = 0;

    protected CallbackHandler $callbacks;

    public function __construct(
        protected array $tools = [],
        protected ?string $goal = null,
        protected int $maxIterations = 10,
        ?CallbackHandler $callbacks = null,
    ) {
        $this->callbacks = $callbacks ?? new CallbackHandler();
    }

    public function run(string $task)
    {
        while (! $this->isTaskCompleted) {
            $this->currentIteration++;

            if ($this->currentIteration > $this->maxIterations) {
                $this->callbacks->triggerMaxIterationsReached($this->currentIteration, $this->maxIterations);

                return "Max iterations reached: {$this->maxIterations}";
            }

            $this->callbacks->triggerIteration($this->currentIteration, $this->maxIterations);

            $result = $this->think($task);

            $this->updateHistory('thought', $result['thought'] ?? '');

            $this->callbacks->triggerThought($result['thought'] ?? '');

            $this->updateHistory('action', Arr::only($result, ['action', 'action_input']));
            if ($result['action'] === 'final_answer') {
                $this->isTaskCompleted = true;

                $this->checkIfDone($task);

                $this->callbacks->triggerFinalAnswer($result['action_input']);

                return $result['action_input'];
            }

            $observation = $this->callbacks->triggerAction($result['action'], $result['action_input']);
            $this->updateHistory('observation', $observation);

            $this->callbacks->triggerObservation($observation);
        }

    }

    protected function checkIfDone(string $task)
    {
        $prompt = 'Consider the task and the following chat history, can the task be considered complete based on the information provided, or are there still unsolved or unfulfilled tasks? think through it step by step, make sure that ALL the requirements are met in the response, event the small details.';
        $prompt .= "\n\n Chat History: ".$this->prepareContext();
        $prompt .= "\n\n".$this->prepareResponseFormatInstructions();
        $prompt .= "\n\n"."Task: {$task}";

        $response = Brain::json($prompt);

        return $response;
    }

    protected function think(string $task)
    {
        $prompt = implode("\n\n", array_filter([
            $this->goal ? "GOAL: \n{$this->goal}" : '',
            "YOUR TASK: {$task}",
            $this->prepareTools(),
            $this->prepareResponseFormatInstructions(),
            $this->prepareContext(),
        ]));

        $this->callbacks->triggerPrompt($prompt);

        return Brain::temperature(0.5)->slow()->json($prompt);
    }

    protected function updateHistory(string $type, mixed $content)
    {
        $this->intermediateSteps[] = ['type' => $type, 'content' => $content];
    }

    protected function prepareContext(): ?string
    {
        $context = [];

        if (empty($this->intermediateSteps)) {
            return null;
        }

        $context[] = "STEPS PERFORMED SO FAR: \n";

        foreach ($this->intermediateSteps as $item) {

            if ($item['type'] === 'thought') {
                $context[] = "Thought: {$item['content']}";

                continue;
            }

            if ($item['type'] === 'action') {
                $context[] = "Action: {$item['content']['action']}";
                $context[] = 'Action Input: '.(is_array($item['content']['action_input'])
                        ? json_encode($item['content']['action_input'], JSON_PRETTY_PRINT)
                        : $item['content']['action_input']);

                continue;
            }

            $line = 'Observation: ';

            if (is_array($item['content'])) {
                $line .= json_encode($item['content'], JSON_PRETTY_PRINT);
            } else {
                $line .= $item['content'];
            }

            $context[] = trim($line);

        }

        return implode("\n", $context);
    }

    protected function defaultTools(): array
    {
        return [];
    }

    protected function prepareTools(): ?string
    {
        if (count($this->tools) === 0) {
            return null;
        }

        $toolInstructions = '';

        foreach ($this->tools as $toolName => $tool) {

            $toolInstructions .= "\n{$toolName}: {$tool['description']}\n";

            if (isset($tool['arguments']) && is_array($tool['arguments'])) {
                foreach ($tool['arguments'] as $name => $arg) {
                    $toolInstructions .= sprintf('- %s (%s): %s', $name, $arg['type'], $arg['description'])."\n";
                }
            }

        }

        $prefix = "AVAILABLE TOOLS (the action_input arguments are provided underneath the tool names, all of the arguments must be provided ): \n";

        return $prefix.trim($toolInstructions);
    }

    protected function prepareResponseFormatInstructions(): string
    {
        return implode("\n", [
            "Remember: Respond with a JSON object containing the keys: 'thought', 'action' and 'action_input'.",
            "NOTE: If you are unable to provide an answer or use a tool, return a 'final_answer' action with the action_input 'I don't know'.",
            "When you are done, respond with the output {'action': 'final_answer', 'action_input': 'final thoughts, instruction or answer'}",
            'Your response MUST BE IN JSON and ADHERE TO THE REQUIRED FORMAT:',
        ]);
    }
}
