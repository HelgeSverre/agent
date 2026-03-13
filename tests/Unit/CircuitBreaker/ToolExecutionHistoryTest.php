<?php

namespace Tests\Unit\CircuitBreaker;

use App\CircuitBreaker\ToolExecutionHistory;
use App\CircuitBreaker\ToolExecutionRecord;
use PHPUnit\Framework\TestCase;

class ToolExecutionHistoryTest extends TestCase
{
    private ToolExecutionHistory $history;

    protected function setUp(): void
    {
        parent::setUp();
        $this->history = new ToolExecutionHistory(
            maxHistorySize: 100,
            timeWindow: 60,
            similarityThreshold: 0.85
        );
    }

    public function test_adds_execution_record()
    {
        $record = ToolExecutionRecord::success('test_tool', ['param' => 'value'], 'Success');
        $this->history->addExecution($record);

        $stats = $this->history->getStatistics();
        $this->assertEquals(1, $stats['total_records']);
    }

    public function test_blocks_duplicate_executions()
    {
        $params = ['file_path' => '/tmp/test.txt'];

        // Add first execution
        $record1 = ToolExecutionRecord::success('read_file', $params, 'Content');
        $this->history->addExecution($record1);

        // Should not block first duplicate
        $this->assertFalse($this->history->shouldBlockExecution('read_file', $params, 2));

        // Add second execution
        $record2 = ToolExecutionRecord::success('read_file', $params, 'Content');
        $this->history->addExecution($record2);

        // Should block after threshold
        $this->assertTrue($this->history->shouldBlockExecution('read_file', $params, 2));
    }

    public function test_blocks_similar_executions()
    {
        $params1 = ['file_path' => '/tmp/test1.txt'];
        $params2 = ['file_path' => '/tmp/test2.txt']; // Similar path

        // Add executions with similar parameters
        $record1 = ToolExecutionRecord::success('read_file', $params1, 'Content1');
        $record2 = ToolExecutionRecord::success('read_file', $params2, 'Content2');

        $this->history->addExecution($record1);
        $this->history->addExecution($record2);

        // Should block similar execution
        $this->assertTrue($this->history->shouldBlockExecution('read_file', $params1, 2));
    }

    public function test_gets_recent_executions()
    {
        $oldRecord = new ToolExecutionRecord(
            'test_tool',
            ['param' => 'old'],
            time() - 120, // 2 minutes ago
            true,
            'Old result'
        );

        $recentRecord = ToolExecutionRecord::success('test_tool', ['param' => 'recent'], 'Recent result');

        $this->history->addExecution($oldRecord);
        $this->history->addExecution($recentRecord);

        $recent = $this->history->getRecentExecutions('test_tool', 60); // Last minute

        $this->assertCount(1, $recent);
        $this->assertEquals('recent', $recent[0]->parameters['param']);
    }

    public function test_analyzes_execution_patterns()
    {
        $params = ['query' => 'test search'];

        // Add repetitive executions
        for ($i = 0; $i < 5; $i++) {
            $record = ToolExecutionRecord::success('search_web', $params, "Result $i");
            $this->history->addExecution($record);
        }

        $pattern = $this->history->analyzeExecutionPattern('search_web');

        $this->assertEquals(5, $pattern['total_executions']);
        $this->assertEquals(1, $pattern['unique_parameter_sets']);
        $this->assertTrue($pattern['potential_loop']);
        $this->assertEquals('repetitive', $pattern['pattern_type']);
    }

    public function test_detects_cyclical_patterns()
    {
        $params1 = ['file_path' => '/tmp/file1.txt'];
        $params2 = ['file_path' => '/tmp/file2.txt'];
        $params3 = ['file_path' => '/tmp/file3.txt'];

        // Create cyclical pattern
        $this->history->addExecution(ToolExecutionRecord::success('read_file', $params1, 'Content1'));
        $this->history->addExecution(ToolExecutionRecord::success('read_file', $params2, 'Content2'));
        $this->history->addExecution(ToolExecutionRecord::success('read_file', $params3, 'Content3'));
        $this->history->addExecution(ToolExecutionRecord::success('read_file', $params1, 'Content1'));
        $this->history->addExecution(ToolExecutionRecord::success('read_file', $params2, 'Content2'));

        $pattern = $this->history->analyzeExecutionPattern('read_file');

        $this->assertTrue($pattern['potential_loop']);
        $this->assertContains($pattern['pattern_type'], ['cyclical', 'repetitive']);
    }

    public function test_detects_progressive_patterns()
    {
        // Create progressive numeric pattern
        for ($i = 1; $i <= 5; $i++) {
            $params = ['page' => $i];
            $record = ToolExecutionRecord::success('fetch_page', $params, "Page $i content");
            $this->history->addExecution($record);
        }

        $pattern = $this->history->analyzeExecutionPattern('fetch_page');

        $this->assertTrue($pattern['potential_loop']);
        $this->assertEquals('progressive', $pattern['pattern_type']);
    }

    public function test_finds_most_similar_execution()
    {
        $params1 = ['file_path' => '/tmp/test1.txt'];
        $params2 = ['file_path' => '/tmp/test2.txt'];
        $params3 = ['file_path' => '/tmp/different.log'];

        $record1 = ToolExecutionRecord::success('read_file', $params1, 'Content1');
        $record2 = ToolExecutionRecord::success('read_file', $params2, 'Content2');
        $record3 = ToolExecutionRecord::success('read_file', $params3, 'Content3');

        $this->history->addExecution($record1);
        $this->history->addExecution($record2);
        $this->history->addExecution($record3);

        $similar = $this->history->findMostSimilarExecution('read_file', $params1);

        $this->assertNotNull($similar);
        // Should find params2 as most similar to params1 (both .txt files)
        $this->assertEquals($params2, $similar->parameters);
    }

    public function test_cleans_up_old_entries()
    {
        // Add many old records
        for ($i = 0; $i < 50; $i++) {
            $oldRecord = new ToolExecutionRecord(
                'test_tool',
                ['param' => "value$i"],
                time() - 1000, // Very old
                true,
                "Result $i"
            );
            $this->history->addExecution($oldRecord);
        }

        // Add recent record
        $recentRecord = ToolExecutionRecord::success('test_tool', ['param' => 'recent'], 'Recent');
        $this->history->addExecution($recentRecord);

        // Force cleanup by adding more records
        for ($i = 0; $i < 100; $i++) {
            $record = ToolExecutionRecord::success('other_tool', ['param' => $i], "Result $i");
            $this->history->addExecution($record);
        }

        $stats = $this->history->getStatistics();

        // Should have cleaned up old records but kept recent ones
        $this->assertLessThanOrEqual(100, $stats['total_records']);
    }

    public function test_provides_comprehensive_statistics()
    {
        // Add various executions
        $this->history->addExecution(ToolExecutionRecord::success('read_file', ['file' => 'test.txt'], 'Content'));
        $this->history->addExecution(ToolExecutionRecord::failure('write_file', ['file' => 'test.txt'], 'Permission denied'));
        $this->history->addExecution(ToolExecutionRecord::success('search_web', ['query' => 'test'], 'Results'));

        $stats = $this->history->getStatistics();

        $this->assertArrayHasKey('total_records', $stats);
        $this->assertArrayHasKey('tools', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        $this->assertArrayHasKey('oldest_record_age', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);

        $this->assertEquals(3, $stats['total_records']);
        $this->assertEquals(2 / 3, $stats['success_rate'], '', 0.01); // 2 successes out of 3
        $this->assertArrayHasKey('read_file', $stats['tools']);
        $this->assertArrayHasKey('write_file', $stats['tools']);
        $this->assertArrayHasKey('search_web', $stats['tools']);
    }

    public function test_clears_history()
    {
        // Add some records
        for ($i = 0; $i < 5; $i++) {
            $record = ToolExecutionRecord::success('test_tool', ['param' => $i], "Result $i");
            $this->history->addExecution($record);
        }

        $this->assertEquals(5, $this->history->getStatistics()['total_records']);

        // Clear should remove all records
        $this->history->clear();

        $this->assertEquals(0, $this->history->getStatistics()['total_records']);
    }

    public function test_handles_empty_history()
    {
        $pattern = $this->history->analyzeExecutionPattern('nonexistent_tool');

        $this->assertEquals(0, $pattern['total_executions']);
        $this->assertEquals(0, $pattern['unique_parameter_sets']);
        $this->assertFalse($pattern['potential_loop']);
        $this->assertEquals('none', $pattern['pattern_type']);
    }

    public function test_respects_time_window()
    {
        $history = new ToolExecutionHistory(timeWindow: 30); // 30 seconds

        // Add old execution (outside window)
        $oldRecord = new ToolExecutionRecord(
            'test_tool',
            ['param' => 'old'],
            time() - 60, // 1 minute ago
            true,
            'Old result'
        );
        $history->addExecution($oldRecord);

        // Should not block because old record is outside time window
        $this->assertFalse($history->shouldBlockExecution('test_tool', ['param' => 'old'], 1));
    }
}
