# Circuit Breaker Usage Examples

## Basic Usage

The circuit breaker is automatically integrated into the Agent framework and works transparently. Here are some examples of how it prevents loops:

### Example 1: File Reading Loop

```php
// Agent attempts to read the same file repeatedly
$agent = new Agent($tools);

// First attempt - allowed
$result1 = $agent->executeTool('read_file', ['file_path' => '/tmp/config.json']);

// Second attempt - allowed
$result2 = $agent->executeTool('read_file', ['file_path' => '/tmp/config.json']);

// Third attempt - BLOCKED by circuit breaker
$result3 = $agent->executeTool('read_file', ['file_path' => '/tmp/config.json']);
// Returns: "Execution blocked: Potential repetitive loop detected..."
```

### Example 2: Similar Parameters Detection

```php
// Agent tries similar file paths
$agent->executeTool('read_file', ['file_path' => '/tmp/test1.txt']);
$agent->executeTool('read_file', ['file_path' => '/tmp/test2.txt']); // Similar path
$agent->executeTool('read_file', ['file_path' => '/tmp/test3.txt']); // BLOCKED
```

### Example 3: Command Execution Loop

```php
// Agent runs the same command repeatedly
$agent->executeTool('run_command', ['command' => 'ls -la /nonexistent']);
$agent->executeTool('run_command', ['command' => 'ls -la /nonexistent']); // BLOCKED
```

## Configuration Examples

### Custom Thresholds

```php
// In config/circuit_breaker.php
return [
    'failure_threshold' => 5,      // Allow more failures
    'duplicate_threshold' => 1,    // Block immediately on duplicates
    'similarity_threshold' => 0.9, // Require high similarity

    'tool_overrides' => [
        'search_web' => [
            'duplicate_threshold' => 3, // Allow retries for web searches
            'time_window' => 30,        // Shorter window
        ]
    ]
];
```

### Development Mode

```php
return [
    'development' => [
        'reset_on_new_task' => true,    // Reset between tasks
        'debug_mode' => true,           // Verbose logging
        'detailed_logging' => true,     // Log all decisions
    ]
];
```

## Advanced Usage

### Manual Circuit Breaker Management

```php
use App\CircuitBreaker\CircuitBreakerManager;

// Create manager with custom config
$circuitBreaker = new CircuitBreakerManager([
    'enabled' => true,
    'failure_threshold' => 3,
    'duplicate_threshold' => 2
]);

// Check if execution is allowed
if (!$circuitBreaker->canExecute('read_file', ['file_path' => '/tmp/test.txt'])) {
    $message = $circuitBreaker->getBlockedExecutionMessage('read_file', ['file_path' => '/tmp/test.txt']);
    echo $message;
    return;
}

// Execute and record result
$result = $tool->execute(['file_path' => '/tmp/test.txt']);
$circuitBreaker->recordExecution('read_file', ['file_path' => '/tmp/test.txt'], $result);
```

### Monitoring and Analytics

```php
// Get comprehensive status
$status = $circuitBreaker->getStatus();
echo "Total circuit breakers: {$status['total_circuit_breakers']}\n";
echo "Success rate: " . ($status['execution_history']['success_rate'] * 100) . "%\n";

// Analyze patterns for specific tool
$patterns = $circuitBreaker->analyzeToolPatterns('read_file');
if ($patterns['potential_loop']) {
    echo "Detected {$patterns['pattern_type']} pattern with ";
    echo "{$patterns['total_executions']} executions\n";
}

// Get metrics for monitoring systems
$metrics = $circuitBreaker->getMetrics();
```

### Reset Operations

```php
// Reset specific circuit breaker
$circuitBreaker->resetCircuitBreaker('read_file', ['file_path' => '/tmp/test.txt']);

// Reset all circuit breakers
$circuitBreaker->resetAll();

// Disable/enable circuit breaker
$circuitBreaker->setEnabled(false);
```

## Integration with Hooks

The circuit breaker integrates with the Agent hook system:

```php
$hooks = new Hooks();

// Listen for circuit breaker events
$hooks->listen('circuit_breaker_blocked', function($toolName, $toolInput, $message) {
    echo "Circuit breaker blocked {$toolName}: {$message}\n";
});

$hooks->listen('tool_execution_success', function($toolName, $toolInput, $result, $executionTime) {
    echo "Tool {$toolName} succeeded in {$executionTime}s\n";
});

$hooks->listen('tool_execution_error', function($toolName, $toolInput, $result, $executionTime) {
    echo "Tool {$toolName} failed in {$executionTime}s: {$result}\n";
});
```

## Testing Circuit Breaker Behavior

### Unit Test Example

```php
public function test_prevents_file_reading_loop()
{
    $agent = new Agent($this->tools);
    $params = ['file_path' => '/tmp/test.txt'];

    // First two executions should succeed
    $result1 = $agent->executeTool('read_file', $params);
    $result2 = $agent->executeTool('read_file', $params);

    $this->assertStringNotContainsString('Circuit breaker', $result1);
    $this->assertStringNotContainsString('Circuit breaker', $result2);

    // Third execution should be blocked
    $result3 = $agent->executeTool('read_file', $params);
    $this->assertStringContainsString('Circuit breaker', $result3);
}
```

### Integration Test

```php
public function test_circuit_breaker_with_real_tools()
{
    $writeFileTool = new WriteFileTool('/tmp');
    $agent = new Agent([$writeFileTool]);

    // Try to write the same file multiple times
    for ($i = 0; $i < 5; $i++) {
        $result = $agent->executeTool('write_file', [
            'filename' => 'test.txt',
            'content' => 'Test content'
        ]);

        if ($i >= 2) {
            $this->assertStringContainsString('blocked', $result);
            break;
        }
    }
}
```

## Best Practices

### 1. Configure Appropriate Thresholds

```php
// For file operations - be strict
'tool_overrides' => [
    'read_file' => [
        'duplicate_threshold' => 1,
        'similarity_threshold' => 0.95
    ],

    // For web operations - be lenient
    'search_web' => [
        'duplicate_threshold' => 3,
        'failure_threshold' => 5
    ]
]
```

### 2. Monitor Circuit Breaker Activity

```php
// Log circuit breaker events
$hooks->listen('circuit_breaker_blocked', function($toolName, $toolInput, $message) {
    Log::warning("Circuit breaker activated", [
        'tool' => $toolName,
        'parameters' => $toolInput,
        'message' => $message
    ]);
});
```

### 3. Provide Helpful Error Messages

The circuit breaker automatically provides context-aware error messages:

```
Circuit breaker OPEN for tool 'read_file' with parameter '/tmp/config.json'.
Reason: Detected 3 duplicate/similar executions.
This indicates a potential infinite loop or repetitive behavior.
Suggestion: Try a different approach, verify parameters, or wait for recovery.
Recovery available in 45 seconds.
```

### 4. Test Edge Cases

```php
// Test parameter similarity edge cases
public function test_parameter_similarity_edge_cases()
{
    $detector = new ParameterSimilarityDetector();

    // Very similar file paths
    $this->assertTrue($detector->areSimilar(
        ['file_path' => '/tmp/test1.txt'],
        ['file_path' => '/tmp/test2.txt'],
        0.8
    ));

    // Different file types
    $this->assertFalse($detector->areSimilar(
        ['file_path' => '/tmp/test.txt'],
        ['file_path' => '/tmp/test.jpg'],
        0.9
    ));
}
```

## Troubleshooting

### Common Issues

1. **Circuit breaker too sensitive**: Increase `similarity_threshold`
2. **Too many false positives**: Adjust `duplicate_threshold`
3. **Recovery too slow**: Decrease `recovery_timeout`
4. **Memory usage high**: Decrease `max_history_size`

### Debugging

```php
// Enable debug mode
config(['circuit_breaker.development.debug_mode' => true]);

// Check circuit breaker status
$status = $circuitBreaker->getStatus();
var_dump($status);

// Analyze execution patterns
$patterns = $circuitBreaker->analyzeToolPatterns('problematic_tool');
var_dump($patterns);
```
