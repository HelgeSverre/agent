<?php

namespace Tests\Unit\Context;

use App\Agent\Context\Services\MemoryManager;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemoryManagerTest extends TestCase
{
    protected MemoryManager $memoryManager;

    protected function setUp(): void
    {
        parent::setUp();
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
    public function it_stores_and_retrieves_compressed_context()
    {
        $context = [
            'type' => 'compressed_context',
            'content' => 'Test compressed content',
            'metadata' => [
                'compression_timestamp' => time(),
                'critical_facts' => ['fact1', 'fact2'],
                'file_operations' => ['test.php'],
            ],
        ];

        $contextId = $this->memoryManager->storeCompressedContext($context);

        $this->assertNotEmpty($contextId);
        $this->assertStringStartsWith('ctx_', $contextId);

        $retrievedContext = $this->memoryManager->retrieveContext($contextId);

        $this->assertEquals($context, $retrievedContext);
    }

    #[Test]
    public function it_stores_and_retrieves_working_context()
    {
        $sessionId = 'test_session_123';
        $steps = [
            ['type' => 'action', 'content' => 'test action'],
            ['type' => 'observation', 'content' => 'test observation'],
        ];

        $this->memoryManager->storeWorkingContext($sessionId, $steps);

        $retrievedSteps = $this->memoryManager->retrieveContext($sessionId, 'working');

        $this->assertEquals($steps, $retrievedSteps);
    }

    #[Test]
    public function it_searches_contexts_by_metadata()
    {
        // Store multiple contexts with different metadata
        $context1 = [
            'type' => 'compressed_context',
            'content' => 'PHP related content',
            'metadata' => [
                'file_operations' => ['Controller.php', 'Model.php'],
                'critical_facts' => ['PHP', 'Laravel'],
                'compression_timestamp' => time(),
            ],
        ];

        $context2 = [
            'type' => 'compressed_context',
            'content' => 'JavaScript related content',
            'metadata' => [
                'file_operations' => ['app.js', 'component.vue'],
                'critical_facts' => ['JavaScript', 'Vue.js'],
                'compression_timestamp' => time(),
            ],
        ];

        $this->memoryManager->storeCompressedContext($context1);
        $this->memoryManager->storeCompressedContext($context2);

        // Search for PHP-related contexts
        $results = $this->memoryManager->searchContexts([
            'critical_facts' => ['PHP'],
        ]);

        $this->assertCount(1, $results);
        $this->assertStringContains('PHP related content', $results[0]['context']['content']);

        // Search for file operations
        $results = $this->memoryManager->searchContexts([
            'file_operations' => ['Controller.php'],
        ]);

        $this->assertCount(1, $results);
        $this->assertEquals('Controller.php', $results[0]['context']['metadata']['file_operations'][0]);
    }

    #[Test]
    public function it_calculates_relevance_scores_correctly()
    {
        $context1 = [
            'type' => 'compressed_context',
            'content' => 'Highly relevant content',
            'metadata' => [
                'critical_facts' => ['PHP', 'Laravel', 'Testing'],
                'file_operations' => ['TestCase.php'],
                'compression_timestamp' => time(), // Recent
            ],
        ];

        $context2 = [
            'type' => 'compressed_context',
            'content' => 'Less relevant content',
            'metadata' => [
                'critical_facts' => ['JavaScript'],
                'file_operations' => ['script.js'],
                'compression_timestamp' => time() - 3600, // 1 hour ago
            ],
        ];

        $this->memoryManager->storeCompressedContext($context1);
        $this->memoryManager->storeCompressedContext($context2);

        $results = $this->memoryManager->searchContexts([
            'critical_facts' => ['PHP', 'Laravel'],
        ]);

        $this->assertCount(2, $results);

        // First result should be more relevant
        $this->assertGreaterThan(
            $results[1]['relevance_score'],
            $results[0]['relevance_score']
        );

        $this->assertStringContains('Highly relevant', $results[0]['context']['content']);
    }

    #[Test]
    public function it_returns_memory_usage_statistics()
    {
        $context = [
            'type' => 'compressed_context',
            'content' => 'Test content',
            'metadata' => [
                'compression_timestamp' => time(),
                'critical_facts' => ['test'],
            ],
        ];

        $this->memoryManager->storeCompressedContext($context);

        $stats = $this->memoryManager->getMemoryStats();

        $this->assertArrayHasKey('compressed_contexts', $stats);
        $this->assertArrayHasKey('working_contexts', $stats);
        $this->assertArrayHasKey('archived_contexts', $stats);
        $this->assertArrayHasKey('total_size_bytes', $stats);
        $this->assertArrayHasKey('oldest_context', $stats);
        $this->assertArrayHasKey('newest_context', $stats);

        $this->assertEquals(1, $stats['compressed_contexts']);
    }

    #[Test]
    public function it_cleans_up_expired_contexts()
    {
        // Store a context with a timestamp that makes it appear old
        $oldContext = [
            'type' => 'compressed_context',
            'content' => 'Old content',
            'metadata' => [
                'compression_timestamp' => time() - 90000, // Very old
                'critical_facts' => ['old'],
            ],
        ];

        $contextId = $this->memoryManager->storeCompressedContext($oldContext);

        // Manually set the metadata to appear old
        $metadataIndex = Cache::get('agent_context_metadata_index', []);
        if (isset($metadataIndex[$contextId])) {
            $metadataIndex[$contextId]['timestamp'] = time() - 90000;
            Cache::put('agent_context_metadata_index', $metadataIndex);
        }

        $cleaned = $this->memoryManager->cleanup();

        $this->assertArrayHasKey('compressed', $cleaned);
        $this->assertIsInt($cleaned['compressed']);
    }

    #[Test]
    public function it_exports_contexts_successfully()
    {
        $context = [
            'type' => 'compressed_context',
            'content' => 'Export test content',
            'metadata' => [
                'compression_timestamp' => time(),
                'critical_facts' => ['export', 'test'],
            ],
        ];

        $this->memoryManager->storeCompressedContext($context);

        $export = $this->memoryManager->exportContexts();

        $this->assertArrayHasKey('timestamp', $export);
        $this->assertArrayHasKey('contexts', $export);
        $this->assertNotEmpty($export['contexts']);
    }

    #[Test]
    public function it_exports_specific_session_contexts()
    {
        $sessionId = 'test_session_export';
        $workingSteps = [
            ['type' => 'action', 'content' => 'session action'],
            ['type' => 'observation', 'content' => 'session result'],
        ];

        $this->memoryManager->storeWorkingContext($sessionId, $workingSteps);

        $export = $this->memoryManager->exportContexts($sessionId);

        $this->assertEquals($sessionId, $export['session_id']);
        $this->assertArrayHasKey('working', $export['contexts']);
        $this->assertEquals($workingSteps, $export['contexts']['working']);
    }

    #[Test]
    public function it_imports_contexts_successfully()
    {
        $importData = [
            'timestamp' => time(),
            'session_id' => 'import_session',
            'contexts' => [
                'working' => [
                    ['type' => 'action', 'content' => 'imported action'],
                ],
                'ctx_123_abc' => [
                    'type' => 'compressed_context',
                    'content' => 'imported compressed content',
                    'metadata' => ['critical_facts' => ['imported']],
                ],
            ],
        ];

        $result = $this->memoryManager->importContexts($importData);

        $this->assertTrue($result);

        // Verify working context was imported
        $workingContext = $this->memoryManager->retrieveContext('import_session', 'working');
        $this->assertEquals($importData['contexts']['working'], $workingContext);
    }

    #[Test]
    public function it_handles_import_errors_gracefully()
    {
        $invalidData = [
            'invalid' => 'data structure',
        ];

        $result = $this->memoryManager->importContexts($invalidData);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_generates_unique_context_ids()
    {
        $context1 = [
            'type' => 'compressed_context',
            'content' => 'Content 1',
            'metadata' => ['facts' => ['unique1']],
        ];

        $context2 = [
            'type' => 'compressed_context',
            'content' => 'Content 2',
            'metadata' => ['facts' => ['unique2']],
        ];

        $id1 = $this->memoryManager->storeCompressedContext($context1);
        $id2 = $this->memoryManager->storeCompressedContext($context2);

        $this->assertNotEquals($id1, $id2);
        $this->assertStringStartsWith('ctx_', $id1);
        $this->assertStringStartsWith('ctx_', $id2);
    }

    #[Test]
    public function it_handles_context_archiving_logic()
    {
        // This test verifies the archiving logic without actually triggering it
        // (since it requires many contexts and time-based conditions)

        $reflection = new \ReflectionClass($this->memoryManager);
        $method = $reflection->getMethod('compressContext');
        $method->setAccessible(true);

        $originalContext = [
            'type' => 'compressed_context',
            'content' => 'Original content with lots of detail',
            'metadata' => [
                'critical_facts' => ['fact1', 'fact2', 'fact3', 'fact4', 'fact5'],
                'file_operations' => ['file1.php', 'file2.php'],
                'compression_version' => '2.0',
                'verbose_data' => 'This should be removed in archiving',
            ],
        ];

        $compressed = $method->invoke($this->memoryManager, $originalContext);

        $this->assertEquals('compressed_context', $compressed['type']);
        $this->assertEquals('Original content with lots of detail', $compressed['content']);
        $this->assertArrayHasKey('archived_at', $compressed);
        $this->assertArrayHasKey('essential_metadata', $compressed);

        // Should only keep first 3 critical facts
        $this->assertCount(3, $compressed['essential_metadata']['critical_facts']);
        $this->assertArrayNotHasKey('verbose_data', $compressed);
    }

    #[Test]
    public function it_handles_empty_search_criteria()
    {
        $context = [
            'type' => 'compressed_context',
            'content' => 'Test content',
            'metadata' => ['critical_facts' => ['test']],
        ];

        $this->memoryManager->storeCompressedContext($context);

        $results = $this->memoryManager->searchContexts([]);

        // Should return all contexts when no criteria provided
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    #[Test]
    public function it_respects_search_limits()
    {
        // Store multiple contexts
        for ($i = 0; $i < 5; $i++) {
            $context = [
                'type' => 'compressed_context',
                'content' => "Content {$i}",
                'metadata' => [
                    'critical_facts' => ['common_fact'],
                    'sequence' => $i,
                ],
            ];
            $this->memoryManager->storeCompressedContext($context);
        }

        $results = $this->memoryManager->searchContexts(
            ['critical_facts' => ['common_fact']],
            3 // limit to 3 results
        );

        $this->assertLessThanOrEqual(3, count($results));
    }

    #[Test]
    public function it_measures_storage_and_retrieval_performance()
    {
        $context = [
            'type' => 'compressed_context',
            'content' => str_repeat('Large content block. ', 100), // Larger content
            'metadata' => [
                'critical_facts' => array_fill(0, 20, 'fact'), // Many facts
                'file_operations' => array_fill(0, 10, 'file.php'), // Many files
            ],
        ];

        // Measure storage time
        $startTime = microtime(true);
        $contextId = $this->memoryManager->storeCompressedContext($context);
        $storeTime = (microtime(true) - $startTime) * 1000;

        // Measure retrieval time
        $startTime = microtime(true);
        $retrieved = $this->memoryManager->retrieveContext($contextId);
        $retrieveTime = (microtime(true) - $startTime) * 1000;

        $this->assertNotNull($retrieved);
        $this->assertLessThan(50, $storeTime, 'Storage took too long');
        $this->assertLessThan(10, $retrieveTime, 'Retrieval took too long');
    }
}
