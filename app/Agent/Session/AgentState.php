<?php

namespace App\Agent\Session;

use Carbon\Carbon;

class AgentState
{
    public function __construct(
        public string $task,
        public array $intermediateSteps,
        public int $currentIteration,
        public ?string $goal,
        public string $status = 'running',
        public ?array $executionPlan = null,
        public ?string $createdAt = null
    ) {
        $this->createdAt ??= now()->toIso8601String();
    }
    
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'intermediate_steps' => $this->intermediateSteps,
            'current_iteration' => $this->currentIteration,
            'goal' => $this->goal,
            'status' => $this->status,
            'execution_plan' => $this->executionPlan,
            'created_at' => $this->createdAt,
            'updated_at' => now()->toIso8601String(),
        ];
    }
    
    public static function fromArray(array $data): self
    {
        return new self(
            task: $data['task'],
            intermediateSteps: $data['intermediate_steps'] ?? [],
            currentIteration: $data['current_iteration'] ?? 0,
            goal: $data['goal'] ?? null,
            status: $data['status'] ?? 'running',
            executionPlan: $data['execution_plan'] ?? null,
            createdAt: $data['created_at'] ?? now()->toIso8601String()
        );
    }
}