<?php

namespace Tests\Unit\CircuitBreaker;

use App\CircuitBreaker\CircuitBreaker;
use App\CircuitBreaker\CircuitBreakerState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function test_circuit_starts_closed()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key');

        $this->assertEquals(CircuitBreakerState::CLOSED, $circuitBreaker->getState());
        $this->assertTrue($circuitBreaker->canExecute());
    }

    public function test_circuit_opens_after_failure_threshold()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key', failureThreshold: 2);

        // Record failures
        $circuitBreaker->recordFailure();
        $this->assertTrue($circuitBreaker->canExecute()); // Still closed

        $circuitBreaker->recordFailure();
        $this->assertFalse($circuitBreaker->canExecute()); // Now open
        $this->assertEquals(CircuitBreakerState::OPEN, $circuitBreaker->getState());
    }

    public function test_circuit_opens_after_duplicate_threshold()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key', duplicateThreshold: 2);

        // Record duplicates
        $circuitBreaker->recordDuplicate();
        $this->assertTrue($circuitBreaker->canExecute()); // Still closed

        $circuitBreaker->recordDuplicate();
        $this->assertFalse($circuitBreaker->canExecute()); // Now open
        $this->assertEquals(CircuitBreakerState::OPEN, $circuitBreaker->getState());
    }

    public function test_success_resets_failure_count()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key', failureThreshold: 3);

        // Record some failures
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();

        // Record success should reset count
        $circuitBreaker->recordSuccess();

        // Should still be closed and allow more failures
        $this->assertTrue($circuitBreaker->canExecute());
        $this->assertEquals(CircuitBreakerState::CLOSED, $circuitBreaker->getState());

        // Verify reset by failing again
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        $this->assertTrue($circuitBreaker->canExecute()); // Should still be closed
    }

    public function test_circuit_transitions_to_half_open()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key',
            failureThreshold: 1,
            recoveryTimeout: 0 // Immediate recovery for testing
        );

        // Open the circuit
        $circuitBreaker->recordFailure();

        // Since timeout is 0, calling getState() or canExecute() will trigger automatic transition
        // So we test the transition behavior instead
        sleep(1); // Small delay to ensure time passes
        $canExecute = $circuitBreaker->canExecute();
        $state = $circuitBreaker->getState();

        $this->assertTrue($canExecute);
        $this->assertEquals(CircuitBreakerState::HALF_OPEN, $state);
    }

    public function test_half_open_success_closes_circuit()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key',
            failureThreshold: 1,
            recoveryTimeout: 0
        );

        // Open the circuit
        $circuitBreaker->recordFailure();

        // Transition to half-open by checking if execution is allowed
        sleep(1);
        $this->assertTrue($circuitBreaker->canExecute()); // Should allow execution in half-open
        $this->assertEquals(CircuitBreakerState::HALF_OPEN, $circuitBreaker->getState());

        // Success in half-open should close circuit
        $circuitBreaker->recordSuccess();
        $this->assertEquals(CircuitBreakerState::CLOSED, $circuitBreaker->getState());
        $this->assertTrue($circuitBreaker->canExecute());
    }

    public function test_half_open_failure_reopens_circuit()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key',
            failureThreshold: 1,
            recoveryTimeout: 0
        );

        // Open the circuit
        $circuitBreaker->recordFailure();

        // Transition to half-open
        sleep(1);
        $circuitBreaker->canExecute();

        // Failure in half-open should reopen circuit
        $circuitBreaker->recordFailure();
        $this->assertEquals(CircuitBreakerState::OPEN, $circuitBreaker->getState());
        $this->assertFalse($circuitBreaker->canExecute());
    }

    public function test_blocked_execution_message_content()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key', failureThreshold: 1);

        // Open circuit with failure
        $circuitBreaker->recordFailure();

        $message = $circuitBreaker->getBlockedExecutionMessage();

        $this->assertStringContainsString('Circuit breaker open', $message);
        $this->assertStringContainsString('test_tool', $message);
        $this->assertStringContainsString('failed', $message);
    }

    public function test_blocked_execution_message_for_duplicates()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key', duplicateThreshold: 1);

        // Open circuit with duplicate
        $circuitBreaker->recordDuplicate();

        $message = $circuitBreaker->getBlockedExecutionMessage();

        $this->assertStringContainsString('duplicate', $message);
        $this->assertStringContainsString('infinite loop', $message);
    }

    public function test_reset_functionality()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key', failureThreshold: 1);

        // Open the circuit
        $circuitBreaker->recordFailure();
        $this->assertFalse($circuitBreaker->canExecute());

        // Reset should close circuit
        $circuitBreaker->reset();
        $this->assertTrue($circuitBreaker->canExecute());
        $this->assertEquals(CircuitBreakerState::CLOSED, $circuitBreaker->getState());
    }

    public function test_status_information()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key');

        $status = $circuitBreaker->getStatus();

        $this->assertArrayHasKey('tool_name', $status);
        $this->assertArrayHasKey('state', $status);
        $this->assertArrayHasKey('failure_count', $status);
        $this->assertArrayHasKey('duplicate_count', $status);
        $this->assertEquals('test_tool', $status['tool_name']);
        $this->assertEquals('closed', $status['state']);
    }

    public function test_half_open_attempts_limit()
    {
        $circuitBreaker = new CircuitBreaker('test_tool', 'test_key',
            failureThreshold: 1,
            recoveryTimeout: 0,
            maxHalfOpenAttempts: 2
        );

        // Open the circuit
        $circuitBreaker->recordFailure();

        // Transition to half-open
        sleep(1);
        $this->assertTrue($circuitBreaker->canExecute()); // First attempt
        $this->assertTrue($circuitBreaker->canExecute()); // Second attempt
        $this->assertFalse($circuitBreaker->canExecute()); // Should be blocked
    }
}
