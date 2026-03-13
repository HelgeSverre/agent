<?php

namespace App\CircuitBreaker;

/**
 * Individual circuit breaker for a specific tool+parameter combination
 */
class CircuitBreaker
{
    private CircuitBreakerState $state = CircuitBreakerState::CLOSED;

    private int $failureCount = 0;

    private int $duplicateCount = 0;

    private int $lastFailureTime = 0;

    private int $lastStateChange = 0;

    private int $halfOpenAttempts = 0;

    public function __construct(
        private readonly string $toolName,
        private readonly string $parameterKey,
        private readonly int $failureThreshold = 3,
        private readonly int $duplicateThreshold = 2,
        private readonly int $recoveryTimeout = 60,
        private readonly int $maxHalfOpenAttempts = 3
    ) {
        $this->lastStateChange = time();
    }

    /**
     * Check if execution is allowed
     */
    public function canExecute(): bool
    {
        $now = time();

        return match ($this->state) {
            CircuitBreakerState::CLOSED => true,
            CircuitBreakerState::OPEN => $this->shouldTransitionToHalfOpen($now),
            CircuitBreakerState::HALF_OPEN => $this->halfOpenAttempts < $this->maxHalfOpenAttempts
        };
    }

    /**
     * Record successful execution
     */
    public function recordSuccess(): void
    {
        match ($this->state) {
            CircuitBreakerState::CLOSED => $this->handleSuccessInClosed(),
            CircuitBreakerState::HALF_OPEN => $this->handleSuccessInHalfOpen(),
            CircuitBreakerState::OPEN => null // Shouldn't happen, but ignore
        };
    }

    /**
     * Record failed execution
     */
    public function recordFailure(): void
    {
        $this->lastFailureTime = time();

        match ($this->state) {
            CircuitBreakerState::CLOSED => $this->handleFailureInClosed(),
            CircuitBreakerState::HALF_OPEN => $this->handleFailureInHalfOpen(),
            CircuitBreakerState::OPEN => null // Already open
        };
    }

    /**
     * Record duplicate execution
     */
    public function recordDuplicate(): void
    {
        $this->duplicateCount++;

        if ($this->duplicateCount >= $this->duplicateThreshold) {
            $this->transitionToOpen('Duplicate execution threshold exceeded');
        }
    }

    /**
     * Get current state
     */
    public function getState(): CircuitBreakerState
    {
        // Check for automatic state transitions
        $now = time();
        if ($this->state === CircuitBreakerState::OPEN && $this->shouldTransitionToHalfOpen($now)) {
            $this->transitionToHalfOpen();
        }

        return $this->state;
    }

    /**
     * Get detailed status information
     */
    public function getStatus(): array
    {
        $now = time();

        return [
            'tool_name' => $this->toolName,
            'parameter_key' => $this->parameterKey,
            'state' => $this->state->value,
            'failure_count' => $this->failureCount,
            'duplicate_count' => $this->duplicateCount,
            'last_failure_time' => $this->lastFailureTime,
            'time_since_last_failure' => $this->lastFailureTime > 0 ? $now - $this->lastFailureTime : null,
            'recovery_timeout' => $this->recoveryTimeout,
            'time_until_recovery' => $this->state === CircuitBreakerState::OPEN ?
                max(0, $this->recoveryTimeout - ($now - $this->lastStateChange)) : null,
            'half_open_attempts' => $this->halfOpenAttempts,
            'max_half_open_attempts' => $this->maxHalfOpenAttempts,
        ];
    }

    /**
     * Get human-readable blocked execution message
     */
    public function getBlockedExecutionMessage(): string
    {
        $status = $this->getStatus();
        $timeUntilRecovery = $status['time_until_recovery'];

        $message = "Circuit breaker {$this->state->value} for tool '{$this->toolName}'.\n";

        if ($this->duplicateCount >= $this->duplicateThreshold) {
            $message .= "Reason: Detected {$this->duplicateCount} duplicate/similar executions.\n";
            $message .= "This indicates a potential infinite loop or repetitive behavior.\n";
        } elseif ($this->failureCount >= $this->failureThreshold) {
            $message .= "Reason: Tool failed {$this->failureCount} consecutive times.\n";
            $message .= "This prevents further failures and allows the system to recover.\n";
        }

        $message .= "Suggestion: Try a different approach, verify parameters, or wait for recovery.\n";

        if ($timeUntilRecovery !== null && $timeUntilRecovery > 0) {
            $message .= "Recovery available in {$timeUntilRecovery} seconds.";
        } elseif ($this->state === CircuitBreakerState::HALF_OPEN) {
            $message .= 'Circuit is testing recovery - limited attempts available.';
        }

        return $message;
    }

    /**
     * Force reset the circuit breaker
     */
    public function reset(): void
    {
        $this->state = CircuitBreakerState::CLOSED;
        $this->failureCount = 0;
        $this->duplicateCount = 0;
        $this->halfOpenAttempts = 0;
        $this->lastStateChange = time();
    }

    /**
     * Check if circuit should transition from OPEN to HALF_OPEN
     */
    private function shouldTransitionToHalfOpen(int $now): bool
    {
        return ($now - $this->lastStateChange) >= $this->recoveryTimeout;
    }

    /**
     * Handle successful execution in CLOSED state
     */
    private function handleSuccessInClosed(): void
    {
        // Reset counters on success
        if ($this->failureCount > 0) {
            $this->failureCount = 0;
        }
        if ($this->duplicateCount > 0) {
            $this->duplicateCount = max(0, $this->duplicateCount - 1);
        }
    }

    /**
     * Handle failed execution in CLOSED state
     */
    private function handleFailureInClosed(): void
    {
        $this->failureCount++;

        if ($this->failureCount >= $this->failureThreshold) {
            $this->transitionToOpen('Failure threshold exceeded');
        }
    }

    /**
     * Handle successful execution in HALF_OPEN state
     */
    private function handleSuccessInHalfOpen(): void
    {
        $this->transitionToClosed('Recovery successful');
    }

    /**
     * Handle failed execution in HALF_OPEN state
     */
    private function handleFailureInHalfOpen(): void
    {
        $this->halfOpenAttempts++;
        $this->transitionToOpen('Recovery failed');
    }

    /**
     * Transition to OPEN state
     */
    private function transitionToOpen(string $reason): void
    {
        $this->state = CircuitBreakerState::OPEN;
        $this->lastStateChange = time();
        $this->halfOpenAttempts = 0;

        // Log state change if needed
        error_log("Circuit breaker OPENED for {$this->toolName}: {$reason}");
    }

    /**
     * Transition to HALF_OPEN state
     */
    private function transitionToHalfOpen(): void
    {
        $this->state = CircuitBreakerState::HALF_OPEN;
        $this->lastStateChange = time();
        $this->halfOpenAttempts = 0;

        error_log("Circuit breaker HALF_OPEN for {$this->toolName}: Testing recovery");
    }

    /**
     * Transition to CLOSED state
     */
    private function transitionToClosed(string $reason): void
    {
        $this->state = CircuitBreakerState::CLOSED;
        $this->lastStateChange = time();
        $this->failureCount = 0;
        $this->duplicateCount = 0;
        $this->halfOpenAttempts = 0;

        error_log("Circuit breaker CLOSED for {$this->toolName}: {$reason}");
    }
}
