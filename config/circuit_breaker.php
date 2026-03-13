<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the circuit breaker pattern that prevents tool
    | execution loops in the Agent framework.
    |
    */

    'enabled' => env('CIRCUIT_BREAKER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Failure Threshold
    |--------------------------------------------------------------------------
    |
    | Number of consecutive failures before opening the circuit breaker.
    | When reached, the circuit will be opened and block further executions.
    |
    */

    'failure_threshold' => env('CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),

    /*
    |--------------------------------------------------------------------------
    | Duplicate Threshold
    |--------------------------------------------------------------------------
    |
    | Number of duplicate/similar executions before blocking further calls.
    | This prevents infinite loops with identical or very similar parameters.
    |
    */

    'duplicate_threshold' => env('CIRCUIT_BREAKER_DUPLICATE_THRESHOLD', 2),

    /*
    |--------------------------------------------------------------------------
    | Similarity Threshold
    |--------------------------------------------------------------------------
    |
    | Threshold for parameter similarity detection (0.0 to 1.0).
    | Higher values require more similarity to trigger duplicate detection.
    |
    */

    'similarity_threshold' => env('CIRCUIT_BREAKER_SIMILARITY_THRESHOLD', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Time Window
    |--------------------------------------------------------------------------
    |
    | Time window in seconds for analyzing execution patterns.
    | Only executions within this window are considered for loop detection.
    |
    */

    'time_window' => env('CIRCUIT_BREAKER_TIME_WINDOW', 300), // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Recovery Timeout
    |--------------------------------------------------------------------------
    |
    | Time in seconds before attempting to recover from an open circuit.
    | After this timeout, the circuit will transition to half-open state.
    |
    */

    'recovery_timeout' => env('CIRCUIT_BREAKER_RECOVERY_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Max Half Open Attempts
    |--------------------------------------------------------------------------
    |
    | Maximum number of test executions allowed in half-open state.
    | If all attempts fail, the circuit returns to open state.
    |
    */

    'max_half_open_attempts' => env('CIRCUIT_BREAKER_MAX_HALF_OPEN_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Max History Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of execution records to keep in memory.
    | Older records are automatically cleaned up to manage memory usage.
    |
    */

    'max_history_size' => env('CIRCUIT_BREAKER_MAX_HISTORY_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Parameter Similarity Detection
    |--------------------------------------------------------------------------
    |
    | Configuration for detecting similar parameters in tool executions.
    |
    */

    'parameter_similarity' => [
        'string_threshold' => env('CIRCUIT_BREAKER_STRING_THRESHOLD', 0.8),
        'array_threshold' => env('CIRCUIT_BREAKER_ARRAY_THRESHOLD', 0.9),
        'enable_fuzzy_matching' => env('CIRCUIT_BREAKER_FUZZY_MATCHING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | Override default settings for specific tools.
    |
    */

    'tool_overrides' => [
        'read_file' => [
            'duplicate_threshold' => 1, // Block immediately for file operations
            'similarity_threshold' => 0.95, // High similarity required
        ],

        'write_file' => [
            'duplicate_threshold' => 1,
            'failure_threshold' => 2, // Lower tolerance for write failures
        ],

        'run_command' => [
            'duplicate_threshold' => 1, // Prevent command loops
            'similarity_threshold' => 0.9,
        ],

        'search_web' => [
            'duplicate_threshold' => 3, // Allow some retries for web searches
            'time_window' => 60, // Shorter window for web operations
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Metrics
    |--------------------------------------------------------------------------
    |
    | Configuration for monitoring circuit breaker performance.
    |
    */

    'monitoring' => [
        'log_state_changes' => env('CIRCUIT_BREAKER_LOG_STATE_CHANGES', true),
        'log_blocked_executions' => env('CIRCUIT_BREAKER_LOG_BLOCKED_EXECUTIONS', true),
        'metrics_enabled' => env('CIRCUIT_BREAKER_METRICS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Settings
    |--------------------------------------------------------------------------
    |
    | Settings that may be useful during development and testing.
    |
    */

    'development' => [
        'reset_on_new_task' => env('CIRCUIT_BREAKER_RESET_ON_NEW_TASK', true),
        'debug_mode' => env('CIRCUIT_BREAKER_DEBUG', false),
        'detailed_logging' => env('CIRCUIT_BREAKER_DETAILED_LOGGING', false),
    ],
];
