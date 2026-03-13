<?php

namespace Tests\Unit\CircuitBreaker;

use App\CircuitBreaker\CircuitBreakerManager;
use PHPUnit\Framework\TestCase;

class CircuitBreakerManagerTest extends TestCase
{
    private CircuitBreakerManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new CircuitBreakerManager([
            'enabled' => true,
            'failure_threshold' => 2,
            'duplicate_threshold' => 2,
            'similarity_threshold' => 0.85,
            'time_window' => 60,
            'recovery_timeout' => 30,
        ]);
    }

    public function test_allows_execution_initially()
    {
        $this->assertTrue($this->manager->canExecute('test_tool', ['param' => 'value']));
    }

    public function test_blocks_after_duplicate_threshold()
    {
        $params = ['file_path' => '/tmp/test.txt'];

        // First execution - allowed
        $this->assertTrue($this->manager->canExecute('read_file', $params));
        $this->manager->recordExecution('read_file', $params, 'Success');

        // Second execution - still allowed
        $this->assertTrue($this->manager->canExecute('read_file', $params));
        $this->manager->recordExecution('read_file', $params, 'Success');

        // Third execution - should be blocked
        $this->assertFalse($this->manager->canExecute('read_file', $params));
    }

    public function test_blocks_similar_parameters()
    {
        $params1 = ['file_path' => '/tmp/test1.txt'];
        $params2 = ['file_path' => '/tmp/test2.txt'];

        // Execute with similar parameters
        $this->manager->recordExecution('read_file', $params1, 'Success');
        $this->manager->recordExecution('read_file', $params2, 'Success');

        // Should block similar execution
        $this->assertFalse($this->manager->canExecute('read_file', $params1));
    }

    public function test_records_successful_execution()
    {
        $params = ['command' => 'ls -la'];

        $this->manager->recordExecution('run_command', $params, 'file1\nfile2');

        $status = $this->manager->getStatus();
        $this->assertEquals(1, $status['execution_history']['total_records']);
        $this->assertEquals(1.0, $status['execution_history']['success_rate']);
    }

    public function test_records_failed_execution()
    {
        $params = ['file_path' => '/nonexistent.txt'];

        $this->manager->recordExecution('read_file', $params, 'Error: File not found');

        $status = $this->manager->getStatus();
        $this->assertEquals(1, $status['execution_history']['total_records']);
        $this->assertEquals(0.0, $status['execution_history']['success_rate']);
    }

    public function test_provides_detailed_blocked_message()
    {
        $params = ['file_path' => '/tmp/test.txt'];

        // Execute multiple times to trigger block
        $this->manager->recordExecution('read_file', $params, 'Success');
        $this->manager->recordExecution('read_file', $params, 'Success');

        $message = $this->manager->getBlockedExecutionMessage('read_file', $params);

        $this->assertStringContainsString('loop detected', $message);
        $this->assertStringContainsString('read_file', $message);
        $this->assertStringContainsString('parameter sets', $message);
    }

    public function test_analyzes_tool_patterns()
    {
        $params = ['query' => 'test search'];

        // Record multiple executions
        for ($i = 0; $i < 5; $i++) {
            $this->manager->recordExecution('search_web', $params, 'Results...');
        }

        $pattern = $this->manager->analyzeToolPatterns('search_web');

        $this->assertEquals(5, $pattern['total_executions']);
        $this->assertTrue($pattern['potential_loop']);
        $this->assertEquals('repetitive', $pattern['pattern_type']);
    }

    public function test_resets_circuit_breaker()
    {
        $params = ['file_path' => '/tmp/test.txt'];

        // Block execution by exceeding duplicate threshold
        $this->manager->recordExecution('read_file', $params, 'Success');
        $this->manager->recordExecution('read_file', $params, 'Success');

        // Verify it's blocked
        $canExecute = $this->manager->canExecute('read_file', $params);
        $this->assertFalse($canExecute, 'Should be blocked after duplicate threshold');

        // Reset should allow execution again
        $this->manager->resetCircuitBreaker('read_file', $params);

        // Clear the execution history to fully reset the state
        $this->manager->resetAll();

        $this->assertTrue($this->manager->canExecute('read_file', $params));
    }

    public function test_resets_all_circuit_breakers()
    {
        $params1 = ['file_path' => '/tmp/test1.txt'];
        $params2 = ['file_path' => '/tmp/test2.txt'];

        // Block multiple executions
        $this->manager->recordExecution('read_file', $params1, 'Success');
        $this->manager->recordExecution('read_file', $params1, 'Success');
        $this->manager->recordExecution('write_file', $params2, 'Success');
        $this->manager->recordExecution('write_file', $params2, 'Success');

        $this->assertFalse($this->manager->canExecute('read_file', $params1));
        $this->assertFalse($this->manager->canExecute('write_file', $params2));

        // Reset all should allow both
        $this->manager->resetAll();
        $this->assertTrue($this->manager->canExecute('read_file', $params1));
        $this->assertTrue($this->manager->canExecute('write_file', $params2));
    }

    public function test_disabled_circuit_breaker()
    {
        $manager = new CircuitBreakerManager(['enabled' => false]);

        $params = ['file_path' => '/tmp/test.txt'];

        // Should always allow execution when disabled
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($manager->canExecute('read_file', $params));
            $manager->recordExecution('read_file', $params, 'Success');
        }
    }

    public function test_provides_comprehensive_status()
    {
        $params = ['file_path' => '/tmp/test.txt'];

        $this->manager->recordExecution('read_file', $params, 'Success');
        $this->manager->recordExecution('write_file', $params, 'Error: Permission denied');

        $status = $this->manager->getStatus();

        $this->assertArrayHasKey('enabled', $status);
        $this->assertArrayHasKey('total_circuit_breakers', $status);
        $this->assertArrayHasKey('execution_history', $status);
        $this->assertArrayHasKey('circuit_breakers', $status);
        $this->assertArrayHasKey('state_summary', $status);

        $this->assertTrue($status['enabled']);
        $this->assertEquals(2, $status['execution_history']['total_records']);
        $this->assertEquals(0.5, $status['execution_history']['success_rate']);
    }

    public function test_gets_metrics()
    {
        $params = ['file_path' => '/tmp/test.txt'];

        $this->manager->recordExecution('read_file', $params, 'Success');
        $this->manager->recordExecution('read_file', $params, 'Error: Not found');

        $metrics = $this->manager->getMetrics();

        $this->assertArrayHasKey('circuit_breakers_total', $metrics);
        $this->assertArrayHasKey('circuit_breakers_closed', $metrics);
        $this->assertArrayHasKey('executions_total', $metrics);
        $this->assertArrayHasKey('success_rate', $metrics);
        $this->assertArrayHasKey('enabled', $metrics);

        $this->assertTrue($metrics['enabled']);
        $this->assertEquals(2, $metrics['executions_total']);
        $this->assertEquals(0.5, $metrics['success_rate']);
    }

    public function test_updates_configuration()
    {
        $this->manager->updateConfig([
            'failure_threshold' => 5,
            'duplicate_threshold' => 4,
        ]);

        // Test that new config is applied by checking behavior
        $params = ['file_path' => '/tmp/test.txt'];

        // Should now allow more duplicates before blocking
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->manager->canExecute('read_file', $params));
            $this->manager->recordExecution('read_file', $params, 'Success');
        }

        // Should still be allowed with higher threshold
        $this->assertTrue($this->manager->canExecute('read_file', $params));
    }

    public function test_handles_different_tool_types()
    {
        $tools = [
            'read_file' => ['file_path' => '/tmp/test.txt'],
            'write_file' => ['file_path' => '/tmp/output.txt', 'content' => 'test'],
            'search_web' => ['searchTerm' => 'php testing'],
            'browse_website' => ['url' => 'https://example.com'],
            'run_command' => ['command' => 'ls -la'],
        ];

        foreach ($tools as $toolName => $params) {
            $this->assertTrue($this->manager->canExecute($toolName, $params));
            $this->manager->recordExecution($toolName, $params, 'Success');
        }

        $status = $this->manager->getStatus();
        $this->assertEquals(5, $status['execution_history']['total_records']);
    }
}
