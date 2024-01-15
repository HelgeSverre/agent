<?php

namespace App\Agent;

class Prompt
{
    public function __construct(
        protected string $task,
        protected ?string $goal,
        protected ?array $tools = [],
        protected ?array $intermediateSteps = []
    ) {
    }

    public static function make(
        string $task,
        ?string $goal,
        ?array $tools = [],
        ?array $intermediateSteps = []
    ): static {
        return new self($task, $goal, $tools, $intermediateSteps);
    }

    protected function combine(array $parts): string
    {
        return implode("\n\n", array_filter($parts));
    }

    public function decideNextStep(): string
    {
        return $this->combine([
            $this->goal ? "GOAL: \n{$this->goal}" : '',
            $this->prepareTask(),
            $this->prepareTools(),
            $this->prepareResponseFormatInstructions(),
            $this->prepareContext(),
        ]);
    }

    public function evaluateTaskCompletion(): string
    {
        return $this->combine([
            'Consider the task and the following chat history, '
            .'can the task be considered complete based on the information provided, '
            .'or are there still unsolved or unfulfilled tasks? think through it step by step, '
            .'make sure that ALL the requirements are met in the response, event the small details.',
            $this->prepareContext(),
            $this->prepareResponseFormatInstructions(),
            "Task: {$this->task}",
        ]);
    }

    protected function prepareTask(): string
    {
        return "YOUR TASK: {$this->task}";
    }

    protected function prepareTools(): ?string
    {
        if (count($this->tools) == 0) {
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
}
