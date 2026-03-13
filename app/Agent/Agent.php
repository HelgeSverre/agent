<?php

namespace App\Agent;

use App\Agent\Context\ContextManager;
use App\Agent\Execution\ParallelExecutor;
use App\Agent\Session\AgentState;
use App\Agent\Session\SessionManager;
use App\Agent\Tool\Tool;
use App\CircuitBreaker\CircuitBreakerManager;
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

    protected ?ContextManager $contextManager = null;

    protected ?CircuitBreakerManager $circuitBreaker = null;

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

        // Initialize context manager
        $this->contextManager = new ContextManager;

        // Initialize circuit breaker if enabled
        if (config('app.circuit_breaker.enabled', true)) {
            $this->circuitBreaker = new CircuitBreakerManager;
        }

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
        $this->sessionManager = new SessionManager;
    }

    public function setExecutionPlan(array $plan): void
    {
        $this->executionPlan = $plan;
    }

    public function resetForNextTask(): void
    {
        // Condense previous task + answer into a compact conversation exchange
        if ($this->task) {
            $finalAnswer = null;
            foreach (array_reverse($this->intermediateSteps) as $step) {
                if ($step['type'] === 'observation' || $step['type'] === 'action') {
                    if (isset($step['content']['action']) && $step['content']['action'] === 'final_answer') {
                        $finalAnswer = $step['content']['action_input']['answer'] ?? null;
                        break;
                    }
                }
            }

            // Build condensed history from previous exchanges + this one
            $previousExchanges = array_filter($this->intermediateSteps, fn ($s) => $s['type'] === 'previous_exchange');
            $previousExchanges[] = [
                'type' => 'previous_exchange',
                'content' => [
                    'task' => $this->task,
                    'answer' => $finalAnswer ?? '(no final answer recorded)',
                ],
            ];

            // Keep only the last 5 exchanges to prevent unbounded growth
            $this->intermediateSteps = array_slice($previousExchanges, -5);
        } else {
            $this->intermediateSteps = [];
        }

        // Reset task completion state
        $this->isTaskCompleted = false;
        $this->currentIteration = 0;

        // Clear execution plan as it's task-specific
        $this->executionPlan = null;

        // Clear recently executed tools to prevent cross-task interference
        $this->recentlyExecutedTools = [];
        $this->pendingParallelTools = [];
        $this->parallelExecutionCount = 0;

        // Reset circuit breaker for new task if configured
        if (config('circuit_breaker.development.reset_on_new_task', true)) {
            $this->circuitBreaker?->resetAll();
        }
    }

    public static function fromSession(
        string $sessionId,
        array $tools = [],
        ?Hooks $hooks = null,
        int $maxIterations = 20,
        bool $parallelEnabled = false
    ): ?self {
        $manager = new SessionManager;
        $data = $manager->load($sessionId);

        if (! $data) {
            return null;
        }

        $state = AgentState::fromArray($data);

        $agent = new self(
            tools: $tools,
            goal: $state->goal,
            maxIterations: $maxIterations,
            hooks: $hooks,
            parallelEnabled: $parallelEnabled
        );

        // Restore state
        $agent->task = $state->task;
        $agent->intermediateSteps = $state->intermediateSteps;
        $agent->currentIteration = $state->currentIteration;
        $agent->executionPlan = $state->executionPlan;
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

    public function run(string $task): ?string
    {
        $this->isTaskCompleted = false;
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
                    $answer = $toolInput['answer'] ?? $toolInput;
                    $this->isTaskCompleted = true;
                    $this->hooks?->trigger('final_answer', $answer);

                    // Save completed state so session can be restored for follow-up tasks
                    $this->saveSessionState();

                    return $answer;
                }

                // Check if this tool was recently executed in a parallel batch
                if ($this->wasRecentlyExecuted($toolName, $toolInput)) {
                    $key = $this->getToolExecutionKey($toolName, $toolInput);
                    $mainArg = $this->getToolMainArg($toolName, $toolInput);

                    // Check if it failed previously
                    $failedMsg = isset($this->recentlyExecutedTools[$key.':failed']) ? ' (previous attempt failed)' : '';

                    $skipMsg = "[Skipped] {$toolName} ({$mainArg}) - already executed{$failedMsg}";
                    $this->hooks?->trigger('observation', $skipMsg);
                    $this->recordStep('observation', $skipMsg);

                    continue;
                }

                // Check if we should queue this for parallel execution
                if ($this->parallelExecutor && $this->shouldQueueForParallel($nextStep)) {
                    $this->queueToolForParallel($toolName, $toolInput);

                    // Provide feedback that tool is queued
                    $queuedMsg = "[Parallel Queue] Tool '{$toolName}' queued (Queue size: ".count($this->pendingParallelTools).')';
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
            } else {
                // LLM responded with text, no function call — treat as final answer
                $thought = $nextStep['thought'] ?? null;
                if ($thought) {
                    $this->hooks?->trigger('thought', $thought);
                    $this->recordStep('thought', $thought);
                    $this->hooks?->trigger('final_answer', $thought);
                    $this->isTaskCompleted = true;

                    return $thought;
                }
                // Empty response — record and let loop retry (will hit max iterations)
                $this->recordStep('observation', 'Empty response from LLM, retrying...');
            }
        }
    }

    protected function executeTool($toolName, $toolInput): ?string
    {
        /** @var Tool|null $tool */
        $tool = collect($this->tools)->first(fn (Tool $tool) => $tool->name() === $toolName);

        if ($tool === null) {
            return "Tool not found: {$toolName}";
        }

        // Circuit breaker check
        if ($this->circuitBreaker && ! $this->circuitBreaker->canExecute($toolName, $toolInput)) {
            $message = $this->circuitBreaker->getBlockedExecutionMessage($toolName, $toolInput);
            $this->hooks?->trigger('circuit_breaker_blocked', $toolName, $toolInput, $message);

            return $message;
        }

        $this->hooks?->trigger('tool_execution', $toolName, $toolInput);
        $startTime = microtime(true);

        try {
            $result = $tool->execute($toolInput);
            $executionTime = microtime(true) - $startTime;

            // Record execution in circuit breaker
            if ($this->circuitBreaker) {
                $this->circuitBreaker->recordExecution($toolName, $toolInput, $result, $executionTime);
            }

            // Check if the result indicates an error
            if (str_starts_with($result, 'Error:')) {
                $this->hooks?->trigger('tool_execution_error', $toolName, $toolInput, $result, $executionTime);
            } else {
                $this->hooks?->trigger('tool_execution_success', $toolName, $toolInput, $result, $executionTime);
            }

            return $result;
        } catch (Exception $e) {
            $executionTime = microtime(true) - $startTime;
            $errorResult = "Error executing tool: {$e->getMessage()}";

            // Record execution failure in circuit breaker
            if ($this->circuitBreaker) {
                $this->circuitBreaker->recordExecution($toolName, $toolInput, $errorResult, $executionTime);
            }

            $this->hooks?->trigger('tool_execution_exception', $toolName, $toolInput, $e, $executionTime);

            return $errorResult;
        }
    }

    protected function shouldQueueForParallel(array $nextStep): bool
    {
        // Skip if parallel execution is disabled
        if (! $this->parallelExecutor) {
            return false;
        }

        // If we already have tools queued, continue queuing
        if (! empty($this->pendingParallelTools)) {
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
            'arguments' => $toolInput,
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
                $observations[] = "[✓ {$tool['tool']}] ".$result['result'];
            } else {
                $error = $result['error'] ?? 'Unknown error';
                $observations[] = "[✗ {$tool['tool']}] Error: ".$error;

                // Track failed tools to provide better context
                $failedKey = $this->getToolExecutionKey($tool['tool'], $tool['arguments']);
                $this->recentlyExecutedTools[$failedKey.':failed'] = ['time' => $now];
            }
        }

        // Create comprehensive summary
        $summary = "[Parallel Execution Complete]\n";
        $summary .= 'Executed '.count($toolsToExecute)." tools:\n";
        $summary .= implode("\n", $toolSummaries)."\n\n";
        $summary .= "Results:\n".implode("\n---\n", $observations);

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
            fn ($tool) => ($now - $tool['time']) < 30
        );

        // Check if this tool was recently executed
        return isset($this->recentlyExecutedTools[$key]);
    }

    protected function getToolExecutionKey(string $toolName, array $toolInput): string
    {
        // Create simple key based on tool name and main argument
        $mainArg = $this->getToolMainArg($toolName, $toolInput);

        return $toolName.':'.$mainArg;
    }

    protected function getToolMainArg(string $toolName, array $toolInput): string
    {
        return match ($toolName) {
            'search_web' => $toolInput['searchTerm'] ?? '',
            'read_file', 'write_file' => $toolInput['file_path'] ?? $toolInput['filename'] ?? '',
            'browse_website' => $toolInput['url'] ?? '',
            'run_command' => $toolInput['command'] ?? '',
            default => json_encode($toolInput)
        };
    }

    protected function trimIntermediateSteps(): void
    {
        // Use ContextManager for intelligent context management
        if ($this->contextManager) {
            // First, compress old contexts if needed
            $this->intermediateSteps = $this->contextManager->compressOldContext(
                $this->intermediateSteps,
                $this->sessionId
            );

            // Store original steps before trimming
            $originalSteps = $this->intermediateSteps;
            $originalCount = count($originalSteps);

            // Manage context with intelligent compression and trimming
            $this->intermediateSteps = $this->contextManager->manageContext(
                $this->intermediateSteps,
                $this->sessionId
            );

            // If context was trimmed/compressed, add a summary of what was dropped
            if (count($this->intermediateSteps) < $originalCount) {
                $summary = $this->contextManager->summarizeDroppedContext(
                    $originalSteps,
                    $this->intermediateSteps
                );
                if ($summary) {
                    array_unshift($this->intermediateSteps, $summary);
                }
            }

            // Trigger hooks for compression events
            $this->hooks?->trigger('context_management', [
                'original_count' => $originalCount,
                'final_count' => count($this->intermediateSteps),
                'compression_applied' => $originalCount > count($this->intermediateSteps),
            ]);
        } else {
            // Fallback to simple trimming
            if (count($this->intermediateSteps) > $this->maxIntermediateSteps) {
                $this->intermediateSteps = array_slice($this->intermediateSteps, -$this->maxIntermediateSteps);
            }
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

        // Trigger hook for compressed context
        if ($type === 'compressed_context') {
            $this->hooks?->trigger('compressed_context', $content);
        }

        $this->saveSessionState();
    }

    protected function saveSessionState(): void
    {
        if ($this->sessionId && $this->sessionManager) {
            $state = new AgentState(
                task: $this->task ?? '',
                intermediateSteps: $this->intermediateSteps,
                currentIteration: $this->currentIteration,
                goal: $this->goal,
                status: $this->isTaskCompleted ? 'completed' : 'running',
                executionPlan: $this->executionPlan
            );

            $this->sessionManager->save($this->sessionId, $state->toArray());
        }
    }
}
