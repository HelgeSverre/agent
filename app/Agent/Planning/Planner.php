<?php

namespace App\Agent\Planning;

use App\Agent\LLM;
use App\Agent\Tool\Tool;

class Planner
{
    /**
     * Create an execution plan for the given task
     *
     * @param  string  $task  The task to plan for
     * @param  array<Tool>  $availableTools  Available tools
     * @return array The execution plan
     */
    public function createPlan(string $task, array $availableTools): array
    {
        $prompt = $this->buildPlanningPrompt($task, $availableTools);

        // Use LLM::json which already uses a lower temperature
        $response = LLM::json($prompt);

        // Ensure we have a valid plan structure
        if (! $response || ! isset($response['steps']) || ! is_array($response['steps'])) {
            return $this->getDefaultPlan($task);
        }

        return $response;
    }

    /**
     * Build the planning prompt
     */
    private function buildPlanningPrompt(string $task, array $tools): string
    {
        $toolList = array_map(fn (Tool $tool) => "- {$tool->name()}: {$tool->description()}", $tools);

        return "You are a task planner. Create a step-by-step execution plan for this task:

TASK: {$task}

AVAILABLE TOOLS:
".implode("\n", $toolList).'

Create a detailed execution plan that:
1. Breaks down the task into clear, actionable steps
2. Identifies which specific tools are needed for each step
3. Orders steps logically for efficient execution
4. Identifies opportunities for parallel execution where possible
5. Tracks dependencies between steps

Return a JSON response with this exact structure:
{
    "summary": "Brief summary of the plan",
    "steps": [
        {
            "step_number": 1,
            "description": "What this step will do",
            "tools": ["tool_name"],
            "can_parallelize": false,
            "depends_on": []
        }
    ],
    "estimated_tools": 3,
    "complexity": "simple|moderate|complex"
}

Important:
- Each step should have a clear, actionable description
- List actual tool names that will be used (must match available tools exactly)
- Mark steps that could run in parallel with can_parallelize: true
- Use depends_on to indicate step dependencies (array of step numbers)
- Complexity should be: simple (1-2 tools), moderate (3-5 tools), or complex (6+ tools)
- The summary should be concise but informative (1-2 sentences max)';
    }

    /**
     * Get a default plan when planning fails
     */
    private function getDefaultPlan(string $task): array
    {
        return [
            'summary' => 'Execute task using available tools',
            'steps' => [
                [
                    'step_number' => 1,
                    'description' => 'Analyze and execute the requested task',
                    'tools' => [],
                    'can_parallelize' => false,
                    'depends_on' => [],
                ],
            ],
            'estimated_tools' => 1,
            'complexity' => 'simple',
        ];
    }
}
