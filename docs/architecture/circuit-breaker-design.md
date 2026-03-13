# Circuit Breaker Pattern for PHP Agent Framework

## Overview

The Circuit Breaker pattern prevents tool execution loops by monitoring tool calls and stopping execution when patterns indicate infinite loops or repeated failures. This implementation extends the existing `consecutiveFailures` tracking with sophisticated loop detection and state management.

## Architecture Components

### 1. Circuit Breaker States

```
CLOSED ──(failures exceed threshold)──> OPEN
  ↑                                        │
  └──(success after timeout)──── HALF_OPEN ←┘
```

- **CLOSED**: Normal operation, monitoring for failures
- **OPEN**: Circuit is open, blocking tool execution
- **HALF_OPEN**: Testing if the circuit should close again

### 2. Core Components

#### ToolExecutionHistory

- Tracks tool calls with parameters and timestamps
- Implements parameter similarity detection
- Manages execution windows and cleanup

#### CircuitBreakerState

- Manages state transitions (CLOSED → OPEN → HALF_OPEN → CLOSED)
- Tracks failure counts and recovery attempts
- Handles timeout logic for state transitions

#### ParameterSimilarityDetector

- Detects exact parameter matches
- Identifies similar parameters with configurable thresholds
- Handles different parameter types (strings, arrays, objects)

#### CircuitBreakerManager

- Orchestrates all components
- Integrates with Agent.executeTool() method
- Provides configuration and monitoring

## Integration Points

### 1. Agent.php Integration

The circuit breaker integrates at the `executeTool` method level:

```php
protected function executeTool($toolName, $toolInput): ?string
{
    // Circuit breaker check BEFORE execution
    if (!$this->circuitBreaker->canExecute($toolName, $toolInput)) {
        return $this->circuitBreaker->getBlockedExecutionMessage($toolName, $toolInput);
    }

    // Execute tool
    $result = $tool->execute($toolInput);

    // Record execution result
    $this->circuitBreaker->recordExecution($toolName, $toolInput, $result);

    return $result;
}
```

### 2. Configuration Options

```php
'circuit_breaker' => [
    'enabled' => true,
    'failure_threshold' => 3,           // Failures before opening circuit
    'duplicate_threshold' => 2,         // Identical calls before blocking
    'similarity_threshold' => 0.85,     // Parameter similarity threshold (0-1)
    'time_window' => 300,               // Time window in seconds
    'recovery_timeout' => 60,           // Time before attempting recovery
    'max_history_size' => 1000,         // Maximum execution history entries
    'parameter_similarity' => [
        'string_threshold' => 0.8,      // Levenshtein similarity for strings
        'array_threshold' => 0.9,       // Jaccard similarity for arrays
        'enable_fuzzy_matching' => true
    ]
]
```

## Loop Detection Strategies

### 1. Exact Duplicate Detection

- Same tool name + identical parameters
- Immediate blocking after threshold reached

### 2. Parameter Similarity Detection

- Levenshtein distance for strings
- Jaccard similarity for arrays
- Structural similarity for objects

### 3. Pattern Recognition

- Cyclical parameter variations
- Progressive parameter changes
- Time-based execution patterns

## State Transitions

### CLOSED → OPEN

- Triggered by: Failure count exceeds threshold OR duplicate calls exceed threshold
- Action: Block all executions for this tool+parameter combination
- Duration: Until recovery timeout expires

### OPEN → HALF_OPEN

- Triggered by: Recovery timeout expires
- Action: Allow single test execution
- Next: CLOSED (on success) or OPEN (on failure)

### HALF_OPEN → CLOSED

- Triggered by: Successful test execution
- Action: Reset failure counters, resume normal operation

### HALF_OPEN → OPEN

- Triggered by: Failed test execution
- Action: Extend recovery timeout, block executions

## Error Messages and Feedback

The circuit breaker provides helpful feedback to the agent:

```
"Circuit breaker OPEN for tool 'read_file' with parameter 'config.json'.
This tool has been called 5 times with identical/similar parameters in the last 2 minutes.
Reason: Potential infinite loop detected.
Suggestion: Try a different approach or verify the file path exists.
Recovery available in 45 seconds."
```

## Performance Considerations

- History cleanup runs every 100 executions
- Similarity calculations cached for repeated comparisons
- Configurable history size limits memory usage
- Async cleanup of expired entries

## Monitoring and Metrics

The circuit breaker exposes metrics for monitoring:

- Tool execution counts by state
- Average parameter similarity scores
- Circuit open/close events
- Recovery success rates

## Testing Strategy

- Unit tests for each component
- Integration tests with Agent.php
- Performance tests for similarity detection
- Edge cases for parameter variations
- State transition verification
