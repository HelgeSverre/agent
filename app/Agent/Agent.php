<?php

namespace App\Agent;

use App\Agent\Execution\ParallelExecutor;
use App\Agent\Session\AgentState;
use App\Agent\Session\SessionManager;
use App\Agent\Tool\Tool;
use Exception;

class Agent
{
    protected bool $isTaskCompleted = false;

    protected array $intermediateSteps = [];

    protected int $maxIntermediateSteps = 10;

    protected int $currentIteration = 0;

    protected array $toolsSchema = [];
    
    protected ?string $sessionId = null;
    
    protected ?SessionManager $sessionManager = null;
    
    protected ?string $task = null;
    
    protected ?ParallelExecutor $parallelExecutor = null;
    
    protected array $pendingParallelTools = [];
    
    protected array $recentlyExecutedTools = [];
    
    protected int $parallelExecutionCount = 0;
    
    protected ?array $executionPlan = null;

    /**
     * @param  array|Tool[]  $tools
     */
    public function __construct(
        protected array $tools = [],
        protected ?string $goal = null,
        protected int $maxIterations = 10,
        protected ?Hooks $hooks = null,
        protected bool $parallelEnabled = false,
    ) {
        $this->prepareToolsSchema();
        
        // Only initialize parallel executor if enabled
        if ($this->parallelEnabled || config('app.parallel_execution.enabled', false)) {
            $this->parallelExecutor = new ParallelExecutor(
                config('app.parallel_execution.max_processes', 4),
                config('app.parallel_execution.timeout', 30)
            );
        }
    }
    
    public function enableSession(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->sessionManager = new SessionManager();
    }
    
    public function setExecutionPlan(array $plan): void
    {
        $this->executionPlan = $plan;
    }
    
    public static function fromSession(string $sessionId, array $tools = [], ?Hooks $hooks = null): ?self
    {
        $manager = new SessionManager();
        $data = $manager->load($sessionId);
        
        if (!$data) {
            return null;
        }
        
        $state = AgentState::fromArray($data);
        
        $agent = new self(
            tools: $tools,
            goal: $state->goal,
            maxIterations: 10,
            hooks: $hooks,
            parallelEnabled: config('app.parallel_execution.enabled', false)
        );
        
        // Restore state
        $agent->task = $state->task;
        $agent->intermediateSteps = $state->intermediateSteps;
        $agent->currentIteration = $state->currentIteration;
        $agent->enableSession($sessionId);
        
        return $agent;
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
        $this->task = $task;
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

                // Check if this tool was recently executed in a parallel batch
                if ($this->wasRecentlyExecuted($toolName, $toolInput)) {
                    $key = $this->getToolExecutionKey($toolName, $toolInput);
                    $mainArg = $this->getToolMainArg($toolName, $toolInput);
                    
                    // Check if it failed previously
                    $failedMsg = isset($this->recentlyExecutedTools[$key . ':failed']) ? " (previous attempt failed)" : "";
                    
                    $skipMsg = "[Skipped] {$toolName} ({$mainArg}) - already executed{$failedMsg}";
                    $this->hooks?->trigger('observation', $skipMsg);
                    $this->recordStep('observation', $skipMsg);
                    continue;
                }
                
                // Check if we should queue this for parallel execution
                if ($this->parallelExecutor && $this->shouldQueueForParallel($nextStep)) {
                    $this->queueToolForParallel($toolName, $toolInput);
                    
                    // Provide feedback that tool is queued
                    $queuedMsg = "[Parallel Queue] Tool '{$toolName}' queued (Queue size: " . count($this->pendingParallelTools) . ")";
                    $this->hooks?->trigger('observation', $queuedMsg);
                    $this->recordStep('observation', $queuedMsg);
                    
                    // Simplified: Execute immediately when we have 2+ tools
                    if (count($this->pendingParallelTools) >= 2) {
                        $observations = $this->executeParallelTools();
                        
                        // Record all parallel results as a comprehensive observation
                        $this->hooks?->trigger('observation', implode("\n", $observations));
                        $this->recordStep('observation', implode("\n", $observations));
                    }
                } else {
                    // Execute single tool normally
                    $observation = $this->executeTool($toolName, $toolInput);
                    $this->hooks?->trigger('observation', $observation);
                    $this->recordStep('observation', $observation);
                }
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
    
    protected function shouldQueueForParallel(array $nextStep): bool
    {
        // Skip if parallel execution is disabled
        if (!$this->parallelExecutor) {
            return false;
        }
        
        // If we already have tools queued, continue queuing
        if (!empty($this->pendingParallelTools)) {
            return true;
        }
        
        // Check if we've already executed parallel tools recently
        // This prevents re-entering parallel mode for the same task
        $recentSteps = array_slice($this->intermediateSteps, -5);
        foreach ($recentSteps as $step) {
            if ($step['type'] === 'observation' && str_contains($step['content'], '[Parallel Execution Complete]')) {
                return false; // Already did parallel execution
            }
        }
        
        // Limit parallel executions to prevent loops
        if ($this->parallelExecutionCount >= 3) {
            return false; // Already did enough parallel executions
        }
        
        // Check the original task for parallel indicators
        if ($this->task && preg_match('/simultaneously|at the same time|both.*and.*and|in parallel/i', $this->task)) {
            return true;
        }
        
        return false;
    }
    
    
    protected function queueToolForParallel(string $toolName, array $toolInput): void
    {
        $this->pendingParallelTools[] = [
            'id' => uniqid('tool_'),
            'tool' => $toolName,
            'arguments' => $toolInput
        ];
    }
    
    
    protected function executeParallelTools(): array
    {
        if (empty($this->pendingParallelTools)) {
            return [];
        }
        
        $this->hooks?->trigger('parallel_execution_start', count($this->pendingParallelTools));
        
        // Store tools before execution
        $toolsToExecute = $this->pendingParallelTools;
        
        // Increment execution count
        $this->parallelExecutionCount++;
        
        // Execute tools in parallel
        $results = $this->parallelExecutor->executeParallel($toolsToExecute);
        
        // Track executed tools using simplified key
        $now = time();
        foreach ($toolsToExecute as $tool) {
            $key = $this->getToolExecutionKey($tool['tool'], $tool['arguments']);
            $this->recentlyExecutedTools[$key] = ['time' => $now];
        }
        
        // Clear the queue
        $this->pendingParallelTools = [];
        
        // Process results
        $observations = [];
        $toolSummaries = [];
        
        foreach ($toolsToExecute as $tool) {
            $result = $results[$tool['id']] ?? null;
            
            // Create summary of what was executed
            $mainArg = $this->getToolMainArg($tool['tool'], $tool['arguments']);
            $toolSummaries[] = "- {$tool['tool']} ({$mainArg})";
            
            if ($result && $result['success']) {
                $observations[] = "[✓ {$tool['tool']}] " . $result['result'];
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $observations[] = "[✗ {$tool['tool']}] Error: " . $error;
                
                // Track failed tools to provide better context
                $failedKey = $this->getToolExecutionKey($tool['tool'], $tool['arguments']);
                $this->recentlyExecutedTools[$failedKey . ':failed'] = ['time' => $now];
            }
        }
        
        // Create comprehensive summary
        $summary = "[Parallel Execution Complete]\n";
        $summary .= "Executed " . count($toolsToExecute) . " tools:\n";
        $summary .= implode("\n", $toolSummaries) . "\n\n";
        $summary .= "Results:\n" . implode("\n---\n", $observations);
        
        // Return as single observation
        $observations = [$summary];
        
        $this->hooks?->trigger('parallel_execution_complete', count($observations));
        
        return $observations;
    }
    
    protected function wasRecentlyExecuted(string $toolName, array $toolInput): bool
    {
        // Simplified: Create a unique key for tool+args
        $key = $this->getToolExecutionKey($toolName, $toolInput);
        
        // Clean up old entries (older than 30 seconds)
        $now = time();
        $this->recentlyExecutedTools = array_filter(
            $this->recentlyExecutedTools,
            fn($tool) => ($now - $tool['time']) < 30
        );
        
        // Check if this tool was recently executed
        return isset($this->recentlyExecutedTools[$key]);
    }
    
    protected function getToolExecutionKey(string $toolName, array $toolInput): string
    {
        // Create simple key based on tool name and main argument
        $mainArg = $this->getToolMainArg($toolName, $toolInput);
        return $toolName . ':' . $mainArg;
    }
    
    protected function getToolMainArg(string $toolName, array $toolInput): string
    {
        return match($toolName) {
            'search_web' => $toolInput['searchTerm'] ?? '',
            'read_file', 'write_file' => $toolInput['file_path'] ?? $toolInput['filename'] ?? '',
            'browse_website' => $toolInput['url'] ?? '',
            'run_command' => $toolInput['command'] ?? '',
            default => json_encode($toolInput)
        };
    }

    protected function evaluateTaskCompletion(string $task)
    {
        $prompt = Prompt::make(
            task: $task,
            goal: $this->goal,
            tools: $this->tools,
            intermediateSteps: $this->intermediateSteps,
            executionPlan: $this->executionPlan
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
            executionPlan: $this->executionPlan
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
        
        // Auto-save if session enabled
        if ($this->sessionId && $this->sessionManager) {
            $state = new AgentState(
                task: $this->task ?? '',
                intermediateSteps: $this->intermediateSteps,
                currentIteration: $this->currentIteration,
                goal: $this->goal,
                status: $this->isTaskCompleted ? 'completed' : 'running'
            );
            
            $this->sessionManager->save($this->sessionId, $state->toArray());
        }
    }
}
