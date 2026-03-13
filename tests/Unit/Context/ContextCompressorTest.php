<?php

namespace Tests\Unit\Context;

use App\Agent\Context\ContextCompressor;
use App\Agent\Context\Services\MemoryManager;
use App\Agent\Context\Services\PerformanceMonitor;
use App\Agent\LLM;
use Illuminate\Support\Facades\Config;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContextCompressorTest extends TestCase
{
    protected ContextCompressor $compressor;

    protected $memoryManagerMock;

    protected $performanceMonitorMock;

    protected $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->memoryManagerMock = Mockery::mock(MemoryManager::class);
        $this->performanceMonitorMock = Mockery::mock(PerformanceMonitor::class);

        // Set up configuration
        $this->originalConfig = config('app.context_compression', []);
        Config::set('app.context_compression', [
            'llm' => [
                'max_tokens' => 1000,
            ],
        ]);

        $this->compressor = new ContextCompressor;
    }

    protected function tearDown(): void
    {
        Config::set('app.context_compression', $this->originalConfig);
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_null_for_empty_steps()
    {
        $result = $this->compressor->compressSteps([]);
        $this->assertNull($result);
    }

    #[Test]
    public function it_performs_simple_compression_for_small_step_groups()
    {
        $steps = [
            [
                'type' => 'action',
                'content' => [
                    'action' => 'write_file',
                    'action_input' => ['filename' => 'test.php'],
                ],
            ],
            [
                'type' => 'observation',
                'content' => 'File written successfully',
            ],
        ];

        $result = $this->compressor->compressSteps($steps);

        $this->assertIsArray($result);
        $this->assertEquals('compressed_context', $result['type']);
        $this->assertStringContains('write_file', $result['content']);
    }

    #[Test]
    public function it_selects_appropriate_compression_strategy()
    {
        // Test simple strategy (less than 5 steps)
        $smallSteps = $this->createMockSteps(3);
        $result = $this->compressor->compressSteps($smallSteps);
        $this->assertEquals('compressed_context', $result['type']);

        // Test with more steps should use intelligent or LLM compression
        $largeSteps = $this->createMockSteps(8);
        $result = $this->compressor->compressSteps($largeSteps);
        $this->assertEquals('compressed_context', $result['type']);
    }

    #[Test]
    #[DataProvider('stepPriorityDataProvider')]
    public function it_classifies_step_priority_correctly(array $step, int $expectedMinPriority)
    {
        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('classifyStepPriority');
        $method->setAccessible(true);

        $priority = $method->invoke($this->compressor, $step);

        $this->assertGreaterThanOrEqual($expectedMinPriority, $priority);
    }

    #[Test]
    #[DataProvider('stepCategorizationDataProvider')]
    public function it_categorizes_steps_correctly(array $step, ?string $expectedCategory)
    {
        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('categorizeStep');
        $method->setAccessible(true);

        $category = $method->invoke($this->compressor, $step);

        $this->assertEquals($expectedCategory, $category);
    }

    #[Test]
    public function it_detects_patterns_in_conversation_steps()
    {
        $steps = [
            ['type' => 'action', 'content' => ['action' => 'read_file']],
            ['type' => 'action', 'content' => ['action' => 'write_file']],
            ['type' => 'action', 'content' => ['action' => 'read_file']],
            ['type' => 'action', 'content' => ['action' => 'write_file']],
            ['type' => 'action', 'content' => ['action' => 'read_file']],
        ];

        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('detectPatterns');
        $method->setAccessible(true);

        $patterns = $method->invoke($this->compressor, $steps);

        $this->assertIsArray($patterns);
        $this->assertNotEmpty($patterns);
    }

    #[Test]
    public function it_truncates_long_observations_intelligently()
    {
        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('truncateObservation');
        $method->setAccessible(true);

        // Test file written pattern
        $observation = 'File written to /path/to/very/long/filename/that/should/be/shortened.php';
        $result = $method->invoke($this->compressor, $observation, 50);
        $this->assertStringContains('File written:', $result);
        $this->assertLessThanOrEqual(50, strlen($result));

        // Test file contents pattern
        $observation = 'File contents: This is a very long content that should be truncated';
        $result = $method->invoke($this->compressor, $observation, 50);
        $this->assertStringContains('File read:', $result);

        // Test error pattern
        $observation = 'Error: Something went wrong with the very long error message';
        $result = $method->invoke($this->compressor, $observation, 50);
        $this->assertStringContains('Error:', $result);
    }

    #[Test]
    public function it_formats_steps_for_llm_consumption()
    {
        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('formatStepForLLM');
        $method->setAccessible(true);

        // Test action formatting
        $step = [
            'type' => 'action',
            'content' => [
                'action' => 'write_file',
                'action_input' => ['filename' => 'test.php'],
            ],
        ];

        $result = $method->invoke($this->compressor, $step);
        $this->assertStringContains('Action: Created/modified file test.php', $result);

        // Test observation formatting
        $step = [
            'type' => 'observation',
            'content' => 'File operation completed successfully',
        ];

        $result = $method->invoke($this->compressor, $step);
        $this->assertStringContains('Result:', $result);

        // Test thought formatting
        $step = [
            'type' => 'thought',
            'content' => 'I need to analyze this situation carefully before proceeding',
        ];

        $result = $method->invoke($this->compressor, $step);
        $this->assertStringContains('Thought:', $result);
    }

    #[Test]
    public function it_extracts_file_operations_correctly()
    {
        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('extractFileOperation');
        $method->setAccessible(true);

        // Test write_file action
        $step = [
            'type' => 'action',
            'content' => [
                'action' => 'write_file',
                'action_input' => ['filename' => 'example.php'],
            ],
        ];

        $result = $method->invoke($this->compressor, $step);
        $this->assertEquals('Created/modified: example.php', $result);

        // Test read_file action
        $step = [
            'type' => 'action',
            'content' => [
                'action' => 'read_file',
                'action_input' => ['filename' => 'config.json'],
            ],
        ];

        $result = $method->invoke($this->compressor, $step);
        $this->assertEquals('Read: config.json', $result);

        // Test observation with "File written" pattern
        $step = [
            'type' => 'observation',
            'content' => 'File written to /app/test.php',
        ];

        $result = $method->invoke($this->compressor, $step);
        $this->assertEquals('Written: /app/test.php', $result);
    }

    #[Test]
    public function it_extracts_user_preferences_and_decisions()
    {
        $reflection = new \ReflectionClass($this->compressor);

        // Test user preference extraction
        $prefMethod = $reflection->getMethod('extractUserPreference');
        $prefMethod->setAccessible(true);

        $step = ['content' => 'I prefer using TypeScript over JavaScript'];
        $result = $prefMethod->invoke($this->compressor, $step);
        $this->assertStringContains('Preference:', $result);

        // Test decision extraction
        $decisionMethod = $reflection->getMethod('extractDecision');
        $decisionMethod->setAccessible(true);

        $step = ['content' => 'I decided to use Laravel for this project'];
        $result = $decisionMethod->invoke($this->compressor, $step);
        $this->assertStringContains('Decision:', $result);
    }

    #[Test]
    public function it_merges_compressed_contexts_correctly()
    {
        $contexts = [
            [
                'type' => 'compressed_context',
                'content' => 'First context summary',
                'metadata' => [
                    'executive_summary' => 'Created user authentication',
                    'critical_facts' => ['JWT tokens implemented'],
                    'file_operations' => ['auth.php'],
                ],
            ],
            [
                'type' => 'compressed_context',
                'content' => 'Second context summary',
                'metadata' => [
                    'executive_summary' => 'Added database migration',
                    'critical_facts' => ['Users table created'],
                    'file_operations' => ['migration.php'],
                ],
            ],
        ];

        $result = $this->compressor->mergeCompressedContexts($contexts);

        $this->assertEquals('compressed_context', $result['type']);
        $this->assertStringContains('Merged context:', $result['content']);
        $this->assertArrayHasKey('merged_context_count', $result['metadata']);
        $this->assertEquals(2, $result['metadata']['merged_context_count']);

        // Check that information is preserved
        $this->assertStringContains('auth.php', $result['content']);
        $this->assertStringContains('migration.php', $result['content']);
    }

    #[Test]
    public function it_handles_empty_merge_input()
    {
        $result = $this->compressor->mergeCompressedContexts([]);
        $this->assertEquals([], $result);
    }

    #[Test]
    public function it_validates_and_enhances_llm_compression_result()
    {
        $llmResult = [
            'executive_summary' => 'Successfully implemented user authentication',
            'critical_facts' => ['JWT tokens', 'Password hashing'],
            'file_operations' => ['User.php', 'AuthController.php'],
            'current_state' => 'Authentication system is ready',
            'compression_metadata' => [
                'original_steps' => 10,
                'compression_ratio' => '0.75',
            ],
        ];

        $analysis = ['total_steps' => 10];

        $reflection = new \ReflectionClass($this->compressor);
        $method = $reflection->getMethod('validateAndEnhanceCompression');
        $method->setAccessible(true);

        $result = $method->invoke($this->compressor, $llmResult, $analysis);

        $this->assertEquals('compressed_context', $result['type']);
        $this->assertStringContains('Successfully implemented user authentication', $result['content']);
        $this->assertArrayHasKey('compression_timestamp', $result['metadata']);
        $this->assertArrayHasKey('original_step_count', $result['metadata']);
        $this->assertEquals('2.0', $result['metadata']['compression_version']);
    }

    #[Test]
    public function it_handles_performance_tracking()
    {
        // Mock performance monitor to verify it's called
        $this->performanceMonitorMock->shouldReceive('recordCompression')
            ->once()
            ->with(
                Mockery::type('array'), // steps
                Mockery::type('array'), // result
                Mockery::type('float')  // compression time
            );

        // Use reflection to inject mock
        $reflection = new \ReflectionClass($this->compressor);
        $property = $reflection->getProperty('performanceMonitor');
        $property->setAccessible(true);
        $property->setValue($this->compressor, $this->performanceMonitorMock);

        $steps = $this->createMockSteps(3);
        $result = $this->compressor->compressSteps($steps);

        $this->assertNotNull($result);
    }

    /**
     * Data provider for step priority testing
     */
    public static function stepPriorityDataProvider(): array
    {
        return [
            'write_file action should be high priority' => [
                ['type' => 'action', 'content' => ['action' => 'write_file']],
                100,
            ],
            'final_answer action should be high priority' => [
                ['type' => 'action', 'content' => ['action' => 'final_answer']],
                100,
            ],
            'observation with file written should be high priority' => [
                ['type' => 'observation', 'content' => 'File written to test.php'],
                100,
            ],
            'observation with error should be high priority' => [
                ['type' => 'observation', 'content' => 'Error: Something went wrong'],
                100,
            ],
            'thought should be medium priority' => [
                ['type' => 'thought', 'content' => 'I need to think about this'],
                50,
            ],
            'unknown action should be low priority' => [
                ['type' => 'action', 'content' => ['action' => 'unknown_action']],
                25,
            ],
        ];
    }

    /**
     * Data provider for step categorization testing
     */
    public static function stepCategorizationDataProvider(): array
    {
        return [
            'write_file should be file_operation' => [
                ['type' => 'action', 'content' => ['action' => 'write_file']],
                'file_operation',
            ],
            'read_file should be file_operation' => [
                ['type' => 'action', 'content' => ['action' => 'read_file']],
                'file_operation',
            ],
            'run_command should be file_operation' => [
                ['type' => 'action', 'content' => ['action' => 'run_command']],
                'file_operation',
            ],
            'content with preference should be user_preference' => [
                ['type' => 'thought', 'content' => 'User prefers TypeScript'],
                'user_preference',
            ],
            'content with decision should be decision' => [
                ['type' => 'thought', 'content' => 'I decided to use React'],
                'decision',
            ],
            'content with error should be error' => [
                ['type' => 'observation', 'content' => 'Error: File not found'],
                'error',
            ],
            'normal content should be null' => [
                ['type' => 'thought', 'content' => 'This is normal content'],
                null,
            ],
        ];
    }

    /**
     * Helper method to create mock steps
     */
    private function createMockSteps(int $count): array
    {
        $steps = [];

        for ($i = 0; $i < $count; $i++) {
            $steps[] = [
                'type' => $i % 2 === 0 ? 'action' : 'observation',
                'content' => $i % 2 === 0
                    ? ['action' => 'test_action', 'action_input' => ['param' => "value{$i}"]]
                    : "Test observation {$i}",
            ];
        }

        return $steps;
    }

    #[Test]
    public function it_measures_compression_performance()
    {
        $startTime = microtime(true);

        $steps = $this->createMockSteps(10);
        $result = $this->compressor->compressSteps($steps);

        $endTime = microtime(true);
        $processingTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->assertNotNull($result);
        // Compression should be reasonably fast (under 100ms for 10 steps)
        $this->assertLessThan(100, $processingTime, 'Compression took too long');
    }

    #[Test]
    public function it_compresses_large_step_sets_efficiently()
    {
        // Test with large number of steps
        $steps = $this->createMockSteps(50);

        $startTime = microtime(true);
        $result = $this->compressor->compressSteps($steps);
        $endTime = microtime(true);

        $processingTime = ($endTime - $startTime) * 1000;

        $this->assertNotNull($result);
        $this->assertEquals('compressed_context', $result['type']);

        // Should still be reasonably fast even with 50 steps
        $this->assertLessThan(500, $processingTime, 'Large compression took too long');

        // Content should be significantly shorter than input
        $originalSize = strlen(serialize($steps));
        $compressedSize = strlen($result['content']);
        $this->assertLessThan($originalSize, $compressedSize);
    }
}
