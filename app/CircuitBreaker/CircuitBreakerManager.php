<?php

namespace App\CircuitBreaker;

/**
 * Main circuit breaker manager that orchestrates all circuit breaker functionality
 */
class CircuitBreakerManager
{
    /** @var CircuitBreaker[] */
    private array $circuitBreakers = [];

    private ToolExecutionHistory $executionHistory;

    private ParameterSimilarityDetector $similarityDetector;

    private bool $enabled = true;

    public function __construct(
        private array $config = []
    ) {
        $this->enabled = $config['enabled'] ?? true;

        // Initialize components with configuration
        $this->similarityDetector = new ParameterSimilarityDetector(
            stringThreshold: $config['parameter_similarity']['string_threshold'] ?? 0.8,
            arrayThreshold: $config['parameter_similarity']['array_threshold'] ?? 0.9,
            enableFuzzyMatching: $config['parameter_similarity']['enable_fuzzy_matching'] ?? true
        );

        $this->executionHistory = new ToolExecutionHistory(
            maxHistorySize: $config['max_history_size'] ?? 1000,
            timeWindow: $config['time_window'] ?? 300,
            similarityThreshold: $config['similarity_threshold'] ?? 0.85,
            similarityDetector: $this->similarityDetector
        );
    }

    /**
     * Check if tool execution should be allowed
     */
    public function canExecute(string $toolName, array $parameters): bool
    {
        if (! $this->enabled) {
            return true;
        }

        // Check execution history for duplicates/loops
        if ($this->executionHistory->shouldBlockExecution(
            $toolName,
            $parameters,
            $this->config['duplicate_threshold'] ?? 2
        )) {
            return false;
        }

        // Check individual circuit breaker
        $circuitBreaker = $this->getOrCreateCircuitBreaker($toolName, $parameters);

        return $circuitBreaker->canExecute();
    }

    /**
     * Record execution result and update circuit breakers
     */
    public function recordExecution(string $toolName, array $parameters, string $result, float $executionTime = 0.0): void
    {
        if (! $this->enabled) {
            return;
        }

        $success = ! str_starts_with($result, 'Error:');

        // Create execution record
        $record = $success
            ? ToolExecutionRecord::success($toolName, $parameters, $result, $executionTime)
            : ToolExecutionRecord::failure($toolName, $parameters, $result, $executionTime);

        // Add to history
        $this->executionHistory->addExecution($record);

        // Update circuit breaker
        $circuitBreaker = $this->getOrCreateCircuitBreaker($toolName, $parameters);

        if ($success) {
            $circuitBreaker->recordSuccess();
        } else {
            $circuitBreaker->recordFailure();
        }

        // Check for duplicates in recent history
        $similarExecution = $this->executionHistory->findMostSimilarExecution($toolName, $parameters);
        if ($similarExecution && $similarExecution->timestamp !== $record->timestamp) {
            $circuitBreaker->recordDuplicate();
        }
    }

    /**
     * Get blocked execution message with context
     */
    public function getBlockedExecutionMessage(string $toolName, array $parameters): string
    {
        if (! $this->enabled) {
            return 'Circuit breaker is disabled';
        }

        // Check if blocked by execution history
        if ($this->executionHistory->shouldBlockExecution($toolName, $parameters)) {
            $pattern = $this->executionHistory->analyzeExecutionPattern($toolName);

            $message = "Execution blocked: Potential {$pattern['pattern_type']} loop detected.\n";
            $message .= "Tool '{$toolName}' has been called {$pattern['total_executions']} times ";
            $message .= "with {$pattern['unique_parameter_sets']} unique parameter sets ";
            $message .= "in the last {$pattern['time_span']} seconds.\n";
            $message .= 'Average parameter similarity: '.number_format($pattern['average_similarity'] * 100, 1)."%\n";

            if ($pattern['execution_frequency'] > 0) {
                $message .= 'Execution frequency: '.number_format($pattern['execution_frequency'], 2)." calls/second\n";
            }

            $message .= "\nSuggestion: Modify your approach or parameters to break the pattern.";

            return $message;
        }

        // Check circuit breaker
        $circuitBreaker = $this->getOrCreateCircuitBreaker($toolName, $parameters);

        return $circuitBreaker->getBlockedExecutionMessage();
    }

    /**
     * Get comprehensive status of all circuit breakers
     */
    public function getStatus(): array
    {
        $status = [
            'enabled' => $this->enabled,
            'total_circuit_breakers' => count($this->circuitBreakers),
            'execution_history' => $this->executionHistory->getStatistics(),
            'circuit_breakers' => [],
        ];

        foreach ($this->circuitBreakers as $key => $circuitBreaker) {
            $status['circuit_breakers'][$key] = $circuitBreaker->getStatus();
        }

        // Group by state
        $stateGroups = [];
        foreach ($status['circuit_breakers'] as $cb) {
            $state = $cb['state'];
            if (! isset($stateGroups[$state])) {
                $stateGroups[$state] = 0;
            }
            $stateGroups[$state]++;
        }

        $status['state_summary'] = $stateGroups;

        return $status;
    }

    /**
     * Analyze execution patterns for a specific tool
     */
    public function analyzeToolPatterns(string $toolName): array
    {
        return $this->executionHistory->analyzeExecutionPattern($toolName);
    }

    /**
     * Reset circuit breaker for specific tool/parameters
     */
    public function resetCircuitBreaker(string $toolName, array $parameters): void
    {
        $key = $this->getCircuitBreakerKey($toolName, $parameters);
        if (isset($this->circuitBreakers[$key])) {
            $this->circuitBreakers[$key]->reset();
        }
    }

    /**
     * Reset all circuit breakers
     */
    public function resetAll(): void
    {
        foreach ($this->circuitBreakers as $circuitBreaker) {
            $circuitBreaker->reset();
        }
        $this->executionHistory->clear();
    }

    /**
     * Enable or disable circuit breaker functionality
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);

        // Update components if needed
        if (isset($config['parameter_similarity'])) {
            $this->similarityDetector = new ParameterSimilarityDetector(
                stringThreshold: $config['parameter_similarity']['string_threshold'] ?? 0.8,
                arrayThreshold: $config['parameter_similarity']['array_threshold'] ?? 0.9,
                enableFuzzyMatching: $config['parameter_similarity']['enable_fuzzy_matching'] ?? true
            );
        }
    }

    /**
     * Get or create circuit breaker for tool/parameter combination
     */
    private function getOrCreateCircuitBreaker(string $toolName, array $parameters): CircuitBreaker
    {
        $key = $this->getCircuitBreakerKey($toolName, $parameters);

        if (! isset($this->circuitBreakers[$key])) {
            $this->circuitBreakers[$key] = new CircuitBreaker(
                toolName: $toolName,
                parameterKey: $key,
                failureThreshold: $this->config['failure_threshold'] ?? 3,
                duplicateThreshold: $this->config['duplicate_threshold'] ?? 2,
                recoveryTimeout: $this->config['recovery_timeout'] ?? 60,
                maxHalfOpenAttempts: $this->config['max_half_open_attempts'] ?? 3
            );
        }

        return $this->circuitBreakers[$key];
    }

    /**
     * Generate unique key for circuit breaker
     */
    private function getCircuitBreakerKey(string $toolName, array $parameters): string
    {
        // Use a simplified key based on main parameter for most tools
        $mainParam = $this->getMainParameter($toolName, $parameters);

        return $toolName.':'.md5($mainParam);
    }

    /**
     * Extract main parameter for key generation
     */
    private function getMainParameter(string $toolName, array $parameters): string
    {
        return match ($toolName) {
            'read_file', 'write_file' => $parameters['file_path'] ?? $parameters['filename'] ?? '',
            'search_web' => $parameters['searchTerm'] ?? $parameters['query'] ?? '',
            'browse_website' => $parameters['url'] ?? '',
            'run_command' => $parameters['command'] ?? '',
            default => json_encode($parameters)
        };
    }

    /**
     * Get metrics for monitoring
     */
    public function getMetrics(): array
    {
        $status = $this->getStatus();

        return [
            'circuit_breakers_total' => $status['total_circuit_breakers'],
            'circuit_breakers_closed' => $status['state_summary']['closed'] ?? 0,
            'circuit_breakers_open' => $status['state_summary']['open'] ?? 0,
            'circuit_breakers_half_open' => $status['state_summary']['half_open'] ?? 0,
            'executions_total' => $status['execution_history']['total_records'],
            'success_rate' => $status['execution_history']['success_rate'],
            'memory_usage_bytes' => $status['execution_history']['memory_usage'],
            'enabled' => $this->enabled,
        ];
    }
}
