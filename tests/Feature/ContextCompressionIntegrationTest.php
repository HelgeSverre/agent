<?php

namespace Tests\Feature;

use App\Agent\Context\ContextCompressor;
use App\Agent\Context\ContextManager;
use App\Agent\Context\Services\MemoryManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContextCompressionIntegrationTest extends TestCase
{
    protected ContextCompressor $compressor;

    protected ContextManager $contextManager;

    protected MemoryManager $memoryManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up realistic configuration
        Config::set('app.context_compression', [
            'llm' => [
                'max_tokens' => 1000,
            ],
            'memory' => [
                'compressed_ttl' => 3600, // 1 hour
                'working_ttl' => 1800, // 30 minutes
                'archive_ttl' => 86400, // 24 hours
                'max_compressed_contexts' => 50,
            ],
        ]);

        $this->compressor = new ContextCompressor;
        $this->contextManager = new ContextManager;
        $this->memoryManager = new MemoryManager;

        // Clear cache before each test
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    #[Test]
    public function it_integrates_context_compression_with_agent_workflow()
    {
        // Simulate a typical agent conversation workflow
        $conversationSteps = $this->createRealisticConversationSteps();

        // Test compression
        $compressedContext = $this->compressor->compressSteps($conversationSteps);

        $this->assertNotNull($compressedContext);
        $this->assertEquals('compressed_context', $compressedContext['type']);
        $this->assertArrayHasKey('metadata', $compressedContext);

        // Verify compression ratio
        $originalSize = strlen(serialize($conversationSteps));
        $compressedSize = strlen($compressedContext['content']);
        $this->assertLessThan($originalSize, $compressedSize);

        // Test memory storage
        $contextId = $this->memoryManager->storeCompressedContext($compressedContext);
        $this->assertNotEmpty($contextId);

        // Test retrieval
        $retrievedContext = $this->memoryManager->retrieveContext($contextId);
        $this->assertEquals($compressedContext, $retrievedContext);
    }

    #[Test]
    public function it_handles_large_conversation_histories_efficiently()
    {
        // Create a large conversation with 100 steps
        $largeConversation = [];

        for ($i = 0; $i < 100; $i++) {
            $largeConversation[] = [
                'type' => 'action',
                'content' => [
                    'action' => 'write_file',
                    'action_input' => [
                        'filename' => "file_{$i}.php",
                        'content' => str_repeat("Code block {$i}. ", 50),
                    ],
                ],
            ];

            $largeConversation[] = [
                'type' => 'observation',
                'content' => "Successfully wrote file_{$i}.php with ".(50 * strlen("Code block {$i}. ")).' characters.',
            ];
        }

        $startTime = microtime(true);
        $compressedContext = $this->compressor->compressSteps($largeConversation);
        $compressionTime = (microtime(true) - $startTime) * 1000;

        $this->assertNotNull($compressedContext);
        $this->assertLessThan(2000, $compressionTime); // Should complete in under 2 seconds

        // Verify significant compression
        $originalSize = strlen(serialize($largeConversation));
        $compressedSize = strlen($compressedContext['content']);
        $compressionRatio = $compressedSize / $originalSize;

        $this->assertLessThan(0.5, $compressionRatio); // Should achieve at least 50% compression
    }

    #[Test]
    public function it_preserves_critical_information_across_compression_cycles()
    {
        $criticalSteps = [
            [
                'type' => 'action',
                'content' => [
                    'action' => 'write_file',
                    'action_input' => [
                        'filename' => '/app/config/database.php',
                        'content' => '<?php return ["default" => "mysql"];',
                    ],
                ],
            ],
            [
                'type' => 'observation',
                'content' => 'File written to /app/config/database.php',
            ],
            [
                'type' => 'action',
                'content' => [
                    'action' => 'run_command',
                    'action_input' => [
                        'command' => 'php artisan migrate:fresh --seed',
                    ],
                ],
            ],
            [
                'type' => 'observation',
                'content' => 'Database migrated successfully. 15 tables created, 1000 records seeded.',
            ],
            [
                'type' => 'action',
                'content' => [
                    'action' => 'final_answer',
                    'action_input' => [
                        'answer' => 'Database setup completed with configuration and migrations.',
                    ],
                ],
            ],
        ];

        $compressed = $this->compressor->compressSteps($criticalSteps);

        // Verify critical information is preserved
        $this->assertStringContains('database.php', $compressed['content']);
        $this->assertStringContains('migrate', strtolower($compressed['content']));

        if (isset($compressed['metadata']['file_operations'])) {
            $this->assertCount(1, $compressed['metadata']['file_operations']);
        }

        if (isset($compressed['metadata']['critical_facts'])) {
            $this->assertNotEmpty($compressed['metadata']['critical_facts']);
        }
    }

    #[Test]
    public function it_supports_context_merging_for_session_continuity()
    {
        // Create multiple compressed contexts from different conversation segments
        $segment1 = $this->createConversationSegment('user_auth', [
            'created UserController.php',
            'implemented JWT authentication',
            'added password hashing',
        ]);

        $segment2 = $this->createConversationSegment('database', [
            'created users migration',
            'added database seeder',
            'configured Eloquent models',
        ]);

        $segment3 = $this->createConversationSegment('frontend', [
            'created React components',
            'implemented login form',
            'added state management',
        ]);

        $compressed1 = $this->compressor->compressSteps($segment1);
        $compressed2 = $this->compressor->compressSteps($segment2);
        $compressed3 = $this->compressor->compressSteps($segment3);

        // Test merging
        $mergedContext = $this->compressor->mergeCompressedContexts([
            $compressed1, $compressed2, $compressed3,
        ]);

        $this->assertNotNull($mergedContext);
        $this->assertEquals('compressed_context', $mergedContext['type']);
        $this->assertArrayHasKey('merged_context_count', $mergedContext['metadata']);
        $this->assertEquals(3, $mergedContext['metadata']['merged_context_count']);

        // Should contain information from all segments
        $content = strtolower($mergedContext['content']);
        $this->assertStringContains('auth', $content);
        $this->assertStringContains('database', $content);
        $this->assertStringContains('react', $content);
    }

    #[Test]
    public function it_maintains_performance_under_concurrent_compression_requests()
    {
        $conversations = [];

        // Prepare multiple conversation sets
        for ($i = 0; $i < 5; $i++) {
            $conversations[] = $this->createRealisticConversationSteps($i);
        }

        $startTime = microtime(true);
        $results = [];

        // Simulate concurrent compression requests
        foreach ($conversations as $conversation) {
            $results[] = $this->compressor->compressSteps($conversation);
        }

        $totalTime = (microtime(true) - $startTime) * 1000;

        // All compressions should succeed
        $this->assertCount(5, $results);
        foreach ($results as $result) {
            $this->assertNotNull($result);
            $this->assertEquals('compressed_context', $result['type']);
        }

        // Should complete all compressions efficiently
        $this->assertLessThan(1000, $totalTime); // Under 1 second total
    }

    #[Test]
    public function it_handles_memory_storage_lifecycle_correctly()
    {
        // Create and store multiple contexts
        $contexts = [];
        for ($i = 0; $i < 10; $i++) {
            $steps = $this->createConversationSegment("task_{$i}", ["completed task {$i}"]);
            $compressed = $this->compressor->compressSteps($steps);
            $contextId = $this->memoryManager->storeCompressedContext($compressed);
            $contexts[$contextId] = $compressed;
        }

        // Verify all contexts are stored
        foreach ($contexts as $contextId => $originalContext) {
            $retrieved = $this->memoryManager->retrieveContext($contextId);
            $this->assertEquals($originalContext, $retrieved);
        }

        // Test memory statistics
        $stats = $this->memoryManager->getMemoryStats();
        $this->assertEquals(10, $stats['compressed_contexts']);
        $this->assertGreaterThan(0, $stats['total_size_bytes']);

        // Test search functionality
        $searchResults = $this->memoryManager->searchContexts([
            'critical_facts' => ['completed'],
        ], 5);

        $this->assertLessThanOrEqual(5, count($searchResults));
        $this->assertGreaterThan(0, count($searchResults));
    }

    #[Test]
    public function it_exports_and_imports_contexts_for_session_persistence()
    {
        $sessionId = 'test_session_'.uniqid();

        // Create working context
        $workingSteps = $this->createRealisticConversationSteps();
        $this->memoryManager->storeWorkingContext($sessionId, $workingSteps);

        // Create compressed contexts
        $compressed1 = $this->compressor->compressSteps($workingSteps);
        $contextId1 = $this->memoryManager->storeCompressedContext($compressed1);

        $additionalSteps = $this->createConversationSegment('additional', ['additional task']);
        $compressed2 = $this->compressor->compressSteps($additionalSteps);
        $contextId2 = $this->memoryManager->storeCompressedContext($compressed2);

        // Export session
        $exported = $this->memoryManager->exportContexts($sessionId);

        $this->assertEquals($sessionId, $exported['session_id']);
        $this->assertArrayHasKey('working', $exported['contexts']);
        $this->assertEquals($workingSteps, $exported['contexts']['working']);

        // Export all contexts
        $allExported = $this->memoryManager->exportContexts();
        $this->assertArrayHasKey('contexts', $allExported);
        $this->assertGreaterThan(1, count($allExported['contexts']));

        // Clear and import
        Cache::flush();

        $importSuccess = $this->memoryManager->importContexts($exported);
        $this->assertTrue($importSuccess);

        // Verify imported data
        $importedWorking = $this->memoryManager->retrieveContext($sessionId, 'working');
        $this->assertEquals($workingSteps, $importedWorking);
    }

    #[Test]
    public function it_integrates_with_context_manager_for_full_workflow()
    {
        $sessionId = 'integration_test_session';

        // Add steps to context manager
        for ($i = 0; $i < 20; $i++) {
            $step = [
                'type' => $i % 2 === 0 ? 'action' : 'observation',
                'content' => $i % 2 === 0 ? ['action' => 'test_action'] : "Test observation {$i}",
            ];

            $this->contextManager->addStep($sessionId, $step);
        }

        // Trigger compression through context manager
        $compressedContext = $this->contextManager->compressAndStore($sessionId);

        $this->assertNotNull($compressedContext);
        $this->assertArrayHasKey('type', $compressedContext);

        // Verify context was properly managed
        $remainingSteps = $this->contextManager->getSteps($sessionId);
        $this->assertLessThan(20, count($remainingSteps)); // Some steps should be compressed
    }

    #[Test]
    public function it_maintains_data_integrity_under_edge_conditions()
    {
        $edgeCases = [
            // Empty steps
            [],
            // Single step
            [['type' => 'action', 'content' => ['action' => 'single']]],
            // Steps with special characters
            [[
                'type' => 'observation',
                'content' => 'Special chars: !@#$%^&*()[]{}|\\"\n\t\r',
            ]],
            // Very long content
            [[
                'type' => 'observation',
                'content' => str_repeat('Very long content. ', 500),
            ]],
            // Nested arrays
            [[
                'type' => 'action',
                'content' => [
                    'action' => 'complex_action',
                    'nested' => [
                        'deep' => ['very' => ['nested' => 'value']],
                    ],
                ],
            ]],
        ];

        foreach ($edgeCases as $i => $steps) {
            $result = $this->compressor->compressSteps($steps);

            if ($steps === []) {
                $this->assertNull($result, 'Empty steps should return null');
            } else {
                $this->assertNotNull($result, "Edge case {$i} should not return null");
                $this->assertArrayHasKey('type', $result);
                $this->assertArrayHasKey('content', $result);

                // Should be able to store and retrieve
                $contextId = $this->memoryManager->storeCompressedContext($result);
                $retrieved = $this->memoryManager->retrieveContext($contextId);
                $this->assertEquals($result, $retrieved);
            }
        }
    }

    /**
     * Create realistic conversation steps for testing
     */
    private function createRealisticConversationSteps(int $variant = 0): array
    {
        $baseSteps = [
            [
                'type' => 'action',
                'content' => [
                    'action' => 'write_file',
                    'action_input' => [
                        'filename' => "Controller{$variant}.php",
                        'content' => "<?php\nclass Controller{$variant} {\n    public function index() {\n        return 'Hello World';\n    }\n}",
                    ],
                ],
            ],
            [
                'type' => 'observation',
                'content' => "File written to Controller{$variant}.php",
            ],
            [
                'type' => 'action',
                'content' => [
                    'action' => 'run_command',
                    'action_input' => [
                        'command' => 'php artisan make:test Controller{$variant}Test',
                    ],
                ],
            ],
            [
                'type' => 'observation',
                'content' => "Test created: tests/Feature/Controller{$variant}Test.php",
            ],
            [
                'type' => 'thought',
                'content' => "I should add validation to the Controller{$variant}.",
            ],
            [
                'type' => 'action',
                'content' => [
                    'action' => 'write_file',
                    'action_input' => [
                        'filename' => "Validation{$variant}.php",
                        'content' => "<?php\nclass Validation{$variant} {\n    public function rules() {\n        return ['name' => 'required'];\n    }\n}",
                    ],
                ],
            ],
            [
                'type' => 'observation',
                'content' => "Validation{$variant}.php created with required rules",
            ],
        ];

        return $baseSteps;
    }

    /**
     * Create a conversation segment with specific theme
     */
    private function createConversationSegment(string $theme, array $completions): array
    {
        $steps = [];

        foreach ($completions as $i => $completion) {
            $steps[] = [
                'type' => 'action',
                'content' => [
                    'action' => 'work_on',
                    'action_input' => [
                        'task' => "{$theme}_task_{$i}",
                        'description' => "Working on {$theme} related task",
                    ],
                ],
            ];

            $steps[] = [
                'type' => 'observation',
                'content' => "Successfully {$completion} for {$theme} module",
            ];
        }

        return $steps;
    }
}
