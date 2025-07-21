<?php

namespace App\Agent;

use Illuminate\Support\Facades\Cache;

class ContextManager
{
    protected int $maxStepsToKeep = 10;

    protected int $memoryTtlMinutes = 60;

    protected string $summarizationModel = 'gpt-3.5-turbo';

    public function __construct(
        protected string $agentId,
        protected ?Hooks $hooks = null,
    ) {}

    public function getContext(array $currentSteps = []): array
    {
        $cachedSummary = $this->getMemorySummary();

        // Prioritize most recent interactions
        $recentSteps = array_slice($currentSteps, -$this->maxStepsToKeep);

        if (! $cachedSummary) {
            return $recentSteps;
        }

        // Add summary as a "thought" step at the beginning
        return array_merge(
            [['type' => 'memory', 'content' => $cachedSummary]],
            $recentSteps
        );
    }

    public function updateMemory(array $allSteps): void
    {
        // Only summarize if we have a substantial history
        if (count($allSteps) < $this->maxStepsToKeep * 2) {
            return;
        }

        // Keep important recent steps out of summarization
        $stepsToSummarize = array_slice($allSteps, 0, -$this->maxStepsToKeep);
        $summary = $this->summarizeSteps($stepsToSummarize);

        $this->hooks?->trigger('memory_updated', $summary);
        $this->storeMemorySummary($summary);
    }

    protected function summarizeSteps(array $steps): string
    {
        $formattedSteps = [];
        foreach ($steps as $step) {
            $type = ucfirst($step['type']);
            $content = is_array($step['content']) ? json_encode($step['content']) : $step['content'];
            $formattedSteps[] = "{$type}: {$content}";
        }

        $prompt = 'Summarize the following interaction history into a concise memory that captures the most important information, '.
            "decisions, and findings. Focus on key facts, conclusions, and context that would be important for future interactions.\n\n".
            implode("\n\n", $formattedSteps);

        try {
            return Brain::model($this->summarizationModel)
                ->fast()
                ->temperature(0.2)
                ->text($prompt);
        } catch (\Exception $e) {
            return 'Error summarizing memory: '.$e->getMessage();
        }
    }

    protected function getMemorySummary(): ?string
    {
        return Cache::get("agent_memory:{$this->agentId}");
    }

    protected function storeMemorySummary(string $summary): void
    {
        Cache::put("agent_memory:{$this->agentId}", $summary, now()->addMinutes($this->memoryTtlMinutes));
    }
}
