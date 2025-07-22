<?php

namespace App\Agent;

use App\Agent\Tool\Tool;

class Prompt
{
    /**
     * @param  Tool[]|null  $tools
     */
    public function __construct(
        protected string $task,
        protected ?string $goal,
        protected ?array $tools = [],
        protected ?array $intermediateSteps = []
    ) {}

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
            '# Agent Task Framework',
            $this->goal ? "## GOAL\n{$this->goal}" : '',
            "## TASK\n{$this->task}",
            $this->prepareTools(),
            $this->prepareSystemInstructions(),
            $this->prepareContext(),
            '## YOUR NEXT STEPS',
            '1. Think step-by-step about what you need to do to solve this task',
            '2. Choose the appropriate tool to use, or provide a final answer if the task is complete',
            '3. If using a tool, be explicit and exact with the inputs required',
        ]);
    }

    public function evaluateTaskCompletion(): string
    {
        return $this->combine([
            '# Task Evaluation Framework',
            'Consider the task and the conversation history below, determine if the task can be considered complete.',
            'Evaluate completion based on the following criteria:',
            '- Have all explicit requirements been fulfilled?',
            '- Is the solution reasonable and correct?',
            "- Has the user's intent been satisfied?",
            $this->prepareContext(),
            '## THE TASK THAT NEEDED TO BE COMPLETED',
            "{$this->task}",
            '## EVALUATION INSTRUCTIONS',
            'Respond with a JSON object that includes:',
            "- status: 'completed' or 'not completed'",
            "- feedback: A helpful explanation of why the task is complete or what's still missing",
            '- tasks: An array of strings of remaining subtasks (empty if completed)',
        ]);
    }

    protected function prepareSystemInstructions(): string
    {
        return implode("\n", [
            '## IMPORTANT GUIDELINES',
            '- Break complex tasks into steps',
            '- Use tools when appropriate',
            '- Provide clear, concise observations',
            '- Be specific with tool inputs',
            '- When complete, use the final_answer function with your conclusion',
        ]);
    }

    protected function prepareTools(): ?string
    {
        if (count($this->tools) == 0) {
            return null;
        }

        $toolInstructions = "## AVAILABLE TOOLS\nYou can use the following tools to complete the task:\n\n";

        foreach ($this->tools as $tool) {
            $toolInstructions .= "### {$tool->name()}\n";
            $toolInstructions .= "{$tool->description()}\n\n";

            if ($tool->arguments()) {
                $toolInstructions .= "Parameters:\n";

                foreach ($tool->arguments() as $arg) {
                    $type = $arg->nullable ? "({$arg->type}, optional)" : "({$arg->type}, required)";
                    $toolInstructions .= sprintf("- %s %s: %s\n", $arg->name, $type, $arg->description ?? '');
                }
            }

            $toolInstructions .= "\n";
        }

        return $toolInstructions;
    }

    protected function prepareContext(): ?string
    {
        if (empty($this->intermediateSteps)) {
            return null;
        }

        $context = ['## CONVERSATION HISTORY'];

        foreach ($this->intermediateSteps as $item) {
            if ($item['type'] === 'thought') {
                $context[] = "**Thought**: {$item['content']}";

                continue;
            }

            if ($item['type'] === 'action') {
                $actionName = $item['content']['action'];
                $actionInput = is_array($item['content']['action_input'])
                    ? json_encode($item['content']['action_input'], JSON_PRETTY_PRINT)
                    : $item['content']['action_input'];

                $context[] = "**Action**: {$actionName}";
                $context[] = "**Action Input**: {$actionInput}";

                continue;
            }

            $observation = is_array($item['content'])
                ? json_encode($item['content'], JSON_PRETTY_PRINT)
                : $item['content'];

            $context[] = "**Observation**: {$observation}";
        }

        return implode("\n\n", $context);
    }
}
