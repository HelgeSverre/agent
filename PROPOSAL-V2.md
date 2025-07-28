# Agent Framework Enhancement Proposal v2

## Overview

This proposal focuses on three key improvements to the existing AI Agent Framework:

1. **Parallel Tool Execution** - Execute independent tools simultaneously
2. **Enhanced Decision Loop** - Smarter planning and execution strategies
3. **Session Persistence** - Save and resume agent tasks

## 1. Parallel Tool Execution

### Current State
- Tools execute sequentially in the order determined by the LLM
- Each tool must complete before the next begins
- No ability to run independent operations concurrently

### Proposed Implementation

#### Process-Based Parallelization
```php
// app/Agent/Execution/ParallelExecutor.php
class ParallelExecutor {
    private int $maxProcesses = 4;
    
    public function canParallelize(array $toolCalls): bool {
        // Detect if tools have no data dependencies
        return count($toolCalls) > 1 && $this->hasNoDependencies($toolCalls);
    }
    
    public function executeParallel(array $toolCalls): array {
        $processes = [];
        $results = [];
        
        foreach ($toolCalls as $call) {
            $process = new Process([
                PHP_BINARY,
                base_path('agent'),
                'internal:execute-tool',
                '--data=' . base64_encode(serialize($call))
            ]);
            
            $process->start();
            $processes[$call['id']] = $process;
        }
        
        // Wait for all processes
        foreach ($processes as $id => $process) {
            $process->wait();
            $results[$id] = unserialize($process->getOutput());
        }
        
        return $results;
    }
}
```

#### Integration with Agent
```php
// Modify Agent::run() to support parallel execution
protected function executeStep(array $decision): string {
    if (isset($decision['parallel_tools'])) {
        $executor = new ParallelExecutor();
        $results = $executor->executeParallel($decision['parallel_tools']);
        return $this->combineResults($results);
    }
    
    // Fall back to sequential execution
    return $this->executeTool($decision['tool'], $decision['arguments']);
}
```

### Benefits
- 2-5x performance improvement for multi-tool tasks
- Better resource utilization
- No changes required to existing tools

## 2. Enhanced Decision Loop

### Current State
- Simple ReAct loop: Think → Act → Observe
- No planning ahead or strategy
- Limited context awareness

### Proposed Improvements

#### Multi-Step Planning
```php
// app/Agent/Planning/Planner.php
class Planner {
    public function createPlan(string $task, array $availableTools): array {
        $prompt = $this->buildPlanningPrompt($task, $availableTools);
        
        $response = $this->llm->complete($prompt, [
            'temperature' => 0.3,  // Lower temperature for planning
            'response_format' => ['type' => 'json_object']
        ]);
        
        return json_decode($response, true);
    }
    
    private function buildPlanningPrompt(string $task, array $tools): string {
        return "Create an execution plan for this task: {$task}
        
        Available tools: " . implode(', ', array_keys($tools)) . "
        
        Return a JSON plan with this structure:
        {
            \"steps\": [
                {
                    \"description\": \"Step description\",
                    \"tools\": [\"tool1\", \"tool2\"],
                    \"can_parallelize\": true,
                    \"depends_on\": []
                }
            ]
        }";
    }
}
```

#### Improved Decision Making
```php
// Enhanced Agent::decideNextStep()
protected function decideNextStep(array $steps): array {
    // Include execution plan in context
    if (!$this->executionPlan) {
        $this->executionPlan = $this->planner->createPlan($this->task, $this->tools);
    }
    
    $prompt = $this->buildDecisionPrompt($steps, $this->executionPlan);
    
    $response = $this->llm->functionCall($prompt, $this->tools);
    
    // Check if multiple tools can run in parallel
    if ($this->canBatchTools($response, $this->executionPlan)) {
        return [
            'parallel_tools' => $this->extractParallelTools($response),
            'thought' => $response['thought']
        ];
    }
    
    return $response;
}
```

#### Adaptive Strategy
```php
// app/Agent/Strategy/AdaptiveStrategy.php
class AdaptiveStrategy {
    private array $performanceHistory = [];
    
    public function selectStrategy(string $task, array $context): string {
        // Analyze task complexity
        $complexity = $this->analyzeComplexity($task);
        
        // Check previous performance
        $historicalPerformance = $this->getHistoricalPerformance($task);
        
        if ($complexity > 0.7 || strlen($task) > 200) {
            return 'plan-first';  // Complex tasks benefit from upfront planning
        } elseif ($historicalPerformance['avg_tools'] > 3) {
            return 'parallel-aggressive';  // History shows multiple tools needed
        } else {
            return 'simple-react';  // Default ReAct for simple tasks
        }
    }
}
```

### Benefits
- Smarter execution paths
- Automatic parallelization opportunities
- Learns from past executions
- Reduces unnecessary LLM calls

## 3. Session Persistence

### Current State
- No way to save progress
- Losing context between runs
- Can't resume interrupted tasks

### Proposed Implementation

#### Session Manager
```php
// app/Agent/Session/SessionManager.php
class SessionManager {
    private string $sessionDir;
    
    public function __construct() {
        $this->sessionDir = storage_path('agent-sessions');
        File::ensureDirectoryExists($this->sessionDir);
    }
    
    public function save(string $sessionId, AgentState $state): void {
        $data = [
            'id' => $sessionId,
            'task' => $state->task,
            'steps' => $state->steps,
            'execution_plan' => $state->executionPlan,
            'context' => $state->context,
            'created_at' => $state->startedAt,
            'updated_at' => now(),
            'status' => $state->status
        ];
        
        File::put(
            $this->sessionPath($sessionId),
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }
    
    public function load(string $sessionId): ?AgentState {
        $path = $this->sessionPath($sessionId);
        
        if (!File::exists($path)) {
            return null;
        }
        
        $data = json_decode(File::get($path), true);
        
        return AgentState::fromArray($data);
    }
    
    public function list(): Collection {
        return collect(File::files($this->sessionDir))
            ->map(fn($file) => json_decode($file->getContents(), true))
            ->sortByDesc('updated_at');
    }
}
```

#### Agent State
```php
// app/Agent/Session/AgentState.php
class AgentState {
    public function __construct(
        public string $task,
        public array $steps = [],
        public ?array $executionPlan = null,
        public array $context = [],
        public ?Carbon $startedAt = null,
        public string $status = 'running'
    ) {
        $this->startedAt ??= now();
    }
    
    public function addStep(array $step): void {
        $this->steps[] = array_merge($step, [
            'timestamp' => now()->toIso8601String()
        ]);
        
        // Keep only last N steps in memory
        if (count($this->steps) > 10) {
            array_shift($this->steps);
        }
    }
    
    public function canResume(): bool {
        return $this->status === 'running' || $this->status === 'paused';
    }
}
```

#### CLI Integration
```php
// Add to RunAgent command
protected function handle(): int {
    $task = $this->argument('task');
    $sessionId = $this->option('session') ?? Str::slug($task);
    
    // Check for resume
    if ($this->option('resume')) {
        $state = $this->sessionManager->load($sessionId);
        if (!$state) {
            $this->error("Session not found: $sessionId");
            return 1;
        }
        
        $this->info("Resuming session: {$state->task}");
        $agent = Agent::fromState($state);
    } else {
        $agent = new Agent($task);
        
        if ($this->option('save-session')) {
            $agent->enableSession($sessionId);
        }
    }
    
    // Auto-save on each step
    $agent->onStep(function($state) use ($sessionId) {
        if ($agent->hasSession()) {
            $this->sessionManager->save($sessionId, $state);
        }
    });
    
    return $agent->run();
}
```

### Benefits
- Resume interrupted tasks
- Audit trail of all operations
- Ability to branch from previous states
- Share sessions between team members

## Implementation Timeline

### Phase 1: Session Persistence (Week 1)
- Implement SessionManager and AgentState
- Add CLI options for save/resume
- Test with real-world tasks

### Phase 2: Parallel Execution (Week 2)
- Build ParallelExecutor
- Modify Agent to detect parallelizable operations
- Add process management and error handling

### Phase 3: Enhanced Decision Loop (Week 3-4)
- Implement Planner for multi-step planning
- Add adaptive strategy selection
- Integrate with parallel execution

## Backward Compatibility

All changes are additive and maintain backward compatibility:
- Existing tools work without modification
- Sequential execution remains the default
- Session saving is opt-in via CLI flags
- Planning phase can be disabled via config

## Performance Impact

Expected improvements:
- **Parallel execution**: 2-5x faster for multi-tool tasks
- **Smart planning**: 20-30% fewer LLM calls
- **Session resume**: Near-instant startup for interrupted tasks

## Example Usage

```bash
# Run with session saving
php agent run "analyze codebase and generate documentation" --save-session=doc-gen

# Resume if interrupted
php agent resume doc-gen

# Run with aggressive parallelization
php agent run "search for Laravel news and PHP trends" --parallel --strategy=aggressive

# View session history
php agent sessions

# Run with planning
php agent run "refactor the authentication system" --plan-first
```