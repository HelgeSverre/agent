<?php

namespace App\Agent;

use HelgeSverre\Brain\Facades\Brain;

class PlanningAgent extends Agent
{
    protected ?array $plan = null;

    protected int $currentPlanStep = 0;

    public function run(string $task)
    {
        $this->hooks?->trigger('start', $task);

        // Create a plan before execution
        $plan = $this->createPlan($task);
        $this->plan = $plan['steps'];
        $this->hooks?->trigger('plan_created', $this->plan);

        while (! $this->isTaskCompleted) {
            $this->trimIntermediateSteps();
            $this->currentIteration++;

            if ($this->currentIteration > $this->maxIterations) {
                $this->hooks?->trigger('max_iteration', $this->currentIteration, $this->maxIterations);

                return "Max iterations reached: {$this->maxIterations}";
            }

            // Get the current step from the plan
            $currentStep = $this->plan[$this->currentPlanStep] ?? null;
            $this->hooks?->trigger('plan_step', $this->currentPlanStep + 1, count($this->plan), $currentStep);

            // Execute the step
            $nextStep = $this->executeStep($task, $currentStep);

            // Process results as in the original agent
            // [Same implementation as original agent]

            // After execution, reflect on progress
            if ($this->currentPlanStep % 3 == 0 || $this->isLastStep()) {
                $reflection = $this->reflectOnProgress($task);
                $this->hooks?->trigger('reflection', $reflection);

                // Update plan if needed
                if ($reflection['update_plan']) {
                    $newPlan = $this->revisePlan($task, $reflection['feedback']);
                    $this->plan = $newPlan['steps'];
                    $this->hooks?->trigger('plan_revised', $this->plan);
                }
            }

            $this->currentPlanStep++;
        }
    }

    protected function createPlan(string $task): array
    {
        $prompt = "# Planning Task\n\n".
            "You need to create a detailed plan to accomplish this task:\n\n".
            "{$task}\n\n".
            "Break this down into a series of specific steps that can be executed with the tools available to you.\n".
            "For each step, include:\n".
            "1. A clear description of what needs to be done\n".
            "2. Which tool to use (if applicable)\n".
            "3. What information is needed for this step\n".
            "4. How to verify if the step was successful\n\n".
            "Respond with a JSON object with a 'steps' array, where each step has 'description', 'tool', 'required_info', and 'success_criteria' fields.";

        return Brain::temperature(0.2)->json($prompt);
    }

    protected function executeStep(string $task, ?array $stepPlan): array
    {
        $enrichedTask = "Task: {$task}\n\n".
            'Current step plan: '.json_encode($stepPlan, JSON_PRETTY_PRINT)."\n\n".
            'Execute this specific step using the appropriate tool or provide a final answer if complete.';

        return $this->decideNextStep($enrichedTask);
    }

    protected function reflectOnProgress(string $task): array
    {
        $prompt = "# Progress Reflection\n\n".
            "Review the progress made on this task:\n\n".
            "{$task}\n\n".
            "Original plan:\n".json_encode($this->plan, JSON_PRETTY_PRINT)."\n\n".
            "Steps completed so far:\n".json_encode(array_slice($this->plan, 0, $this->currentPlanStep), JSON_PRETTY_PRINT)."\n\n".
            "Conversation history:\n".$this->formatIntermediateSteps()."\n\n".
            "Based on progress so far, evaluate:\n".
            "1. Is the plan still effective for completing the task?\n".
            "2. Are there any unexpected challenges or opportunities?\n".
            "3. Should the plan be updated or should execution continue?\n\n".
            "Respond with a JSON object including 'assessment', 'update_plan' (boolean), and 'feedback' fields.";

        return Brain::temperature(0.3)->json($prompt);
    }

    protected function revisePlan(string $task, string $feedback): array
    {
        $prompt = "# Plan Revision\n\n".
            "You need to revise the plan for this task based on new information:\n\n".
            "Task: {$task}\n\n".
            "Current plan:\n".json_encode($this->plan, JSON_PRETTY_PRINT)."\n\n".
            "Steps completed so far:\n".json_encode(array_slice($this->plan, 0, $this->currentPlanStep), JSON_PRETTY_PRINT)."\n\n".
            "Reason for revision:\n{$feedback}\n\n".
            "Create a revised plan from this point forward. Keep completed steps in mind.\n\n".
            "Respond with a JSON object with a 'steps' array of remaining steps.";

        return Brain::temperature(0.3)->json($prompt);
    }

    protected function formatIntermediateSteps(): string
    {
        // Format intermediate steps for cleaner display in prompts
        $formatted = [];
        foreach (array_slice($this->intermediateSteps, -5) as $step) {
            $type = ucfirst($step['type']);
            $content = is_array($step['content']) ? json_encode($step['content'], JSON_PRETTY_PRINT) : $step['content'];
            $formatted[] = "**{$type}**: {$content}";
        }

        return implode("\n\n", $formatted);
    }

    protected function isLastStep(): bool
    {
        return $this->currentPlanStep === count($this->plan) - 1;
    }
}
