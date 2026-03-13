<?php

namespace Tests\Feature;

use App\Agent\Agent;
use App\CircuitBreaker\CircuitBreakerManager;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CircuitBreakerIntegrationTest extends TestCase
{
    protected CircuitBreakerManager $circuitBreakerManager;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure circuit breaker for testing
        $config = [
            'enabled' => true,
            'failure_threshold' => 3,
            'duplicate_threshold' => 2,
            'recovery_timeout' => 5, // Short timeout for testing
            'max_half_open_attempts' => 2,
        ];

        Config::set('app.circuit_breaker', $config);

        $this->circuitBreakerManager = new CircuitBreakerManager($config);
        $this->agent = app(Agent::class);
    }

    #[Test]
    public function it_integrates_with_agent_tool_execution()
    {
        // Mock a tool that will fail
        $failingToolName = 'failing_test_tool';
        $parameters = ['file_path' => '/nonexistent/path.txt'];

        // First execution should be allowed
        $this->assertTrue($this->circuitBreakerManager->canExecute($failingToolName, $parameters));
        $this->circuitBreakerManager->recordExecution($failingToolName, $parameters, 'Error: File not found');

        // Second execution should still be allowed (duplicate threshold is 2)
        $this->assertTrue($this->circuitBreakerManager->canExecute($failingToolName, $parameters));
        $this->circuitBreakerManager->recordExecution($failingToolName, $parameters, 'Error: File not found');

        // Third execution should be blocked due to duplicate detection
        $this->assertFalse($this->circuitBreakerManager->canExecute($failingToolName, $parameters));

        // Verify blocked message is informative
        $message = $this->circuitBreakerManager->getBlockedExecutionMessage($failingToolName, $parameters);
        $this->assertStringContainsString('loop detected', $message);
        $this->assertStringContainsString($failingToolName, $message);
    }

    #[Test]
    public function it_prevents_infinite_loops_with_duplicate_detection()
    {
        $toolName = 'read_file';
        $parameters = ['file_path' => '/app/config.json'];
        $result = 'Configuration loaded successfully';

        // Simulate the same operation multiple times (potential loop)
        $executions = 0;
        while ($this->circuitBreakerManager->canExecute($toolName, $parameters) && $executions < 10) {
            $this->circuitBreakerManager->recordExecution($toolName, $parameters, $result);
            $executions++;
        }

        // Should be blocked after duplicate threshold (2)
        $this->assertLessThanOrEqual(2, $executions);
        $this->assertFalse($this->circuitBreakerManager->canExecute($toolName, $parameters));

        // Check the blocking message mentions loop detection
        $message = $this->circuitBreakerManager->getBlockedExecutionMessage($toolName, $parameters);
        $this->assertStringContains('loop detected', $message);
        $this->assertStringContains('duplicate', $message);
    }

    #[Test]
    public function it_handles_different_tools_independently()
    {
        $tools = [
            'write_file' => ['file_path' => '/app/test.php', 'content' => 'test'],
            'read_file' => ['file_path' => '/app/config.json'],
            'run_command' => ['command' => 'ls -la'],
        ];

        // Fail one tool multiple times
        for ($i = 0; $i < 3; $i++) {
            $this->circuitBreakerManager->recordExecution(
                'write_file',
                $tools['write_file'],
                'Error: Permission denied'
            );
        }

        // write_file should be blocked
        $this->assertFalse($this->circuitBreakerManager->canExecute('write_file', $tools['write_file']));

        // Other tools should still be allowed
        $this->assertTrue($this->circuitBreakerManager->canExecute('read_file', $tools['read_file']));
        $this->assertTrue($this->circuitBreakerManager->canExecute('run_command', $tools['run_command']));

        // Record successful executions for other tools
        $this->circuitBreakerManager->recordExecution('read_file', $tools['read_file'], 'File contents...');
        $this->circuitBreakerManager->recordExecution('run_command', $tools['run_command'], 'Directory listing...');

        // Should still be allowed
        $this->assertTrue($this->circuitBreakerManager->canExecute('read_file', $tools['read_file']));
        $this->assertTrue($this->circuitBreakerManager->canExecute('run_command', $tools['run_command']));
    }

    #[Test]
    public function it_recovers_after_timeout_period()
    {
        $toolName = 'recovery_test_tool';
        $parameters = ['test' => 'recovery'];

        // Cause failures to open circuit breaker
        for ($i = 0; $i < 3; $i++) {
            $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Error: Test failure');
        }

        // Should be blocked
        $this->assertFalse($this->circuitBreakerManager->canExecute($toolName, $parameters));

        // Wait for recovery timeout (5 seconds in test config)
        sleep(6);

        // Should now allow limited attempts (half-open state)
        $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));

        // Record successful execution to close circuit
        $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Success: Recovery test passed');

        // Should be fully open again
        $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));
    }

    #[Test]
    public function it_provides_comprehensive_status_and_metrics()
    {
        // Create varied execution scenarios
        $scenarios = [
            ['tool' => 'successful_tool', 'params' => ['id' => 1], 'result' => 'Success', 'count' => 5],
            ['tool' => 'failing_tool', 'params' => ['id' => 2], 'result' => 'Error: Failed', 'count' => 3],
            ['tool' => 'mixed_tool', 'params' => ['id' => 3], 'result' => 'Success', 'count' => 2],
            ['tool' => 'mixed_tool', 'params' => ['id' => 3], 'result' => 'Error: Sometimes fails', 'count' => 1],
        ];

        foreach ($scenarios as $scenario) {
            for ($i = 0; $i < $scenario['count']; $i++) {
                $this->circuitBreakerManager->recordExecution(
                    $scenario['tool'],
                    $scenario['params'],
                    $scenario['result']
                );
            }
        }

        // Test status
        $status = $this->circuitBreakerManager->getStatus();

        $this->assertTrue($status['enabled']);
        $this->assertGreaterThan(0, $status['total_circuit_breakers']);
        $this->assertArrayHasKey('execution_history', $status);
        $this->assertArrayHasKey('circuit_breakers', $status);
        $this->assertArrayHasKey('state_summary', $status);

        // Test metrics
        $metrics = $this->circuitBreakerManager->getMetrics();

        $this->assertArrayHasKey('circuit_breakers_total', $metrics);
        $this->assertArrayHasKey('executions_total', $metrics);
        $this->assertArrayHasKey('success_rate', $metrics);
        $this->assertTrue($metrics['enabled']);

        // Should have recorded all executions
        $this->assertEquals(11, $metrics['executions_total']); // 5+3+2+1

        // Should have at least one open circuit breaker (failing_tool)
        $this->assertGreaterThan(0, $metrics['circuit_breakers_open']);
    }

    #[Test]
    public function it_analyzes_execution_patterns_accurately()
    {
        $toolName = 'pattern_analysis_tool';

        // Create repetitive pattern
        $baseParams = ['action' => 'process'];
        for ($i = 0; $i < 5; $i++) {
            $params = array_merge($baseParams, ['iteration' => $i]);
            $this->circuitBreakerManager->recordExecution(
                $toolName,
                $params,
                "Processed iteration {$i}"
            );
        }

        // Add some duplicate executions
        $duplicateParams = ['action' => 'process', 'iteration' => 1];
        $this->circuitBreakerManager->recordExecution($toolName, $duplicateParams, 'Processed iteration 1');
        $this->circuitBreakerManager->recordExecution($toolName, $duplicateParams, 'Processed iteration 1');

        $analysis = $this->circuitBreakerManager->analyzeToolPatterns($toolName);

        $this->assertArrayHasKey('total_executions', $analysis);
        $this->assertArrayHasKey('unique_parameter_sets', $analysis);
        $this->assertArrayHasKey('pattern_type', $analysis);
        $this->assertArrayHasKey('average_similarity', $analysis);

        $this->assertEquals(7, $analysis['total_executions']);
        $this->assertLessThan(7, $analysis['unique_parameter_sets']); // Should detect some duplicates
        $this->assertGreaterThan(0, $analysis['average_similarity']);
    }

    #[Test]
    public function it_handles_configuration_updates_dynamically()
    {
        // Test with initial configuration
        $toolName = 'config_test_tool';
        $parameters = ['test' => 'config'];

        // Should fail after 3 attempts (initial threshold)
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));
            $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Error: Config test');
        }

        $this->assertFalse($this->circuitBreakerManager->canExecute($toolName, $parameters));

        // Update configuration to higher threshold
        $this->circuitBreakerManager->updateConfig([
            'failure_threshold' => 5,
        ]);

        // Reset for clean test
        $this->circuitBreakerManager->resetAll();

        // Should now allow more failures before blocking
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));
            $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Error: Config test');
        }

        // Should still be allowed with higher threshold
        $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));

        // Fifth failure should trigger circuit breaker
        $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Error: Config test');
        $this->assertFalse($this->circuitBreakerManager->canExecute($toolName, $parameters));
    }

    #[Test]
    public function it_can_be_disabled_for_troubleshooting()
    {
        $toolName = 'disable_test_tool';
        $parameters = ['test' => 'disable'];

        // Disable circuit breaker
        $this->circuitBreakerManager->setEnabled(false);

        // Should allow unlimited failures when disabled
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));
            $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Error: Always fails');
        }

        // Should still be allowed
        $this->assertTrue($this->circuitBreakerManager->canExecute($toolName, $parameters));

        // Re-enable circuit breaker
        $this->circuitBreakerManager->setEnabled(true);

        // Should immediately start blocking due to accumulated failures
        $this->circuitBreakerManager->recordExecution($toolName, $parameters, 'Error: Still failing');
        $this->assertFalse($this->circuitBreakerManager->canExecute($toolName, $parameters));
    }

    #[Test]
    public function it_handles_parameter_similarity_detection()
    {
        $toolName = 'similarity_test_tool';

        // Execute with similar but not identical parameters
        $similarParams = [
            ['file_path' => '/app/config/database.php'],
            ['file_path' => '/app/config/cache.php'],
            ['file_path' => '/app/config/session.php'],
        ];

        foreach ($similarParams as $params) {
            $this->circuitBreakerManager->recordExecution($toolName, $params, 'Config file processed');
        }

        // Should detect similarity and potentially block
        $analysis = $this->circuitBreakerManager->analyzeToolPatterns($toolName);

        $this->assertEquals(3, $analysis['total_executions']);
        $this->assertGreaterThan(0.5, $analysis['average_similarity']); // Should detect path similarity

        // Test with very similar parameters that should be blocked
        $duplicateParams = ['file_path' => '/app/config/database.php']; // Exact duplicate
        $this->circuitBreakerManager->recordExecution($toolName, $duplicateParams, 'Config file processed');

        // Should block due to high similarity
        $this->assertFalse($this->circuitBreakerManager->canExecute($toolName, $duplicateParams));
    }

    #[Test]
    public function it_maintains_performance_under_high_load()
    {
        $tools = ['tool_a', 'tool_b', 'tool_c', 'tool_d', 'tool_e'];
        $executionCount = 200; // High load test

        $startTime = microtime(true);

        for ($i = 0; $i < $executionCount; $i++) {
            $toolName = $tools[$i % 5];
            $parameters = ['iteration' => $i, 'batch' => floor($i / 10)];
            $result = ($i % 10 === 0) ? 'Error: Periodic failure' : 'Success';

            $canExecute = $this->circuitBreakerManager->canExecute($toolName, $parameters);
            if ($canExecute) {
                $this->circuitBreakerManager->recordExecution($toolName, $parameters, $result);
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Should complete high load test in reasonable time
        $this->assertLessThan(1000, $totalTime); // Under 1 second

        // Verify circuit breakers are working
        $status = $this->circuitBreakerManager->getStatus();
        $this->assertGreaterThan(0, $status['total_circuit_breakers']);
        $this->assertGreaterThan(0, $status['execution_history']['total_records']);
    }

    #[Test]
    public function it_provides_detailed_debugging_information()
    {
        $toolName = 'debug_test_tool';
        $parameters = ['debug' => true, 'trace_id' => 'test-123'];

        // Create mixed success/failure pattern
        $results = ['Success', 'Success', 'Error: Debug failure', 'Error: Debug failure', 'Success'];

        foreach ($results as $result) {
            if ($this->circuitBreakerManager->canExecute($toolName, $parameters)) {
                $this->circuitBreakerManager->recordExecution($toolName, $parameters, $result);
            }
        }

        // Get detailed status for debugging
        $status = $this->circuitBreakerManager->getStatus();

        // Should contain detailed execution history
        $this->assertArrayHasKey('execution_history', $status);
        $this->assertArrayHasKey('total_records', $status['execution_history']);
        $this->assertArrayHasKey('success_rate', $status['execution_history']);

        // Should have circuit breaker details
        $this->assertArrayHasKey('circuit_breakers', $status);
        $this->assertNotEmpty($status['circuit_breakers']);

        foreach ($status['circuit_breakers'] as $cbStatus) {
            $this->assertArrayHasKey('tool_name', $cbStatus);
            $this->assertArrayHasKey('state', $cbStatus);
            $this->assertArrayHasKey('failure_count', $cbStatus);
            $this->assertArrayHasKey('last_failure_time', $cbStatus);
        }

        // Test tool pattern analysis
        $analysis = $this->circuitBreakerManager->analyzeToolPatterns($toolName);
        $this->assertArrayHasKey('execution_frequency', $analysis);
        $this->assertArrayHasKey('time_span', $analysis);
    }
}
