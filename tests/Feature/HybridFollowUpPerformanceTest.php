<?php

namespace Tests\Feature;

use App\Agent\Chat\ContextTracker;
use App\Agent\Chat\FollowUpRecognizer;
use App\Agent\Chat\PatternMatcher;
use App\Agent\Chat\RecognitionResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HybridFollowUpPerformanceTest extends TestCase
{
    protected FollowUpRecognizer $recognizer;

    protected PatternMatcher $patternMatcher;

    protected ContextTracker $contextTracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextTracker = new ContextTracker;
        $this->patternMatcher = new PatternMatcher;
        $this->recognizer = new FollowUpRecognizer($this->contextTracker);
    }

    #[Test]
    public function it_achieves_target_api_call_reduction()
    {
        // Test inputs that should be caught by pattern matching (no LLM calls)
        $patternInputs = [
            'yes', 'ok', 'sure', 'no', 'cancel', 'skip',
            'do it', 'make it', 'create it', 'run it',
            'modify it', 'change it', 'update it', 'fix it',
            'another one', 'more examples', 'different approach',
            'what about React?', 'how about TypeScript?', 'can you add tests?',
            'test it', 'verify it', 'check if it works',
            'improve it', 'optimize this', 'add more features',
        ];

        // Execute all pattern inputs
        foreach ($patternInputs as $input) {
            $result = $this->recognizer->analyze($input, 'Previous response about development.');
            // Most should be recognized by pattern matching
        }

        $metrics = $this->recognizer->getMetrics();

        // Should achieve target 75%+ API call reduction
        $this->assertGreaterThanOrEqual(75, $metrics['api_call_reduction']);
        $this->assertLessThanOrEqual(25, $metrics['llm_fallback_rate']);
        $this->assertGreaterThan(50, $metrics['pattern_match_rate']);
    }

    #[Test]
    public function it_processes_pattern_matching_under_1ms()
    {
        $quickInputs = [
            'yes', 'ok', 'do it', 'make it', 'another', 'more', 'test it',
        ];

        $times = [];

        foreach ($quickInputs as $input) {
            $startTime = microtime(true);
            $result = $this->recognizer->analyze($input, 'Quick test response.');
            $processingTime = $result->getProcessingTime();

            if ($result->getProcessingPath() === 'pattern') {
                $times[] = $processingTime;
            }
        }

        // Average pattern matching time should be under 1ms
        $averageTime = array_sum($times) / count($times);
        $this->assertLessThan(1, $averageTime, 'Pattern matching should be under 1ms on average');

        // All individual pattern matches should be under 5ms
        foreach ($times as $time) {
            $this->assertLessThan(5, $time, 'Individual pattern matches should be under 5ms');
        }
    }

    #[Test]
    public function it_processes_context_analysis_under_50ms()
    {
        // Build context first
        $this->contextTracker->addExchange(
            'Create a Laravel application',
            "I've created a Laravel application with user authentication, database migrations, and API endpoints."
        );

        $this->contextTracker->addExchange(
            'Add validation',
            'Added form validation with custom rules and error messages.'
        );

        $contextInputs = [
            'enhance the validation system',
            'add more Laravel features',
            'improve the authentication',
            'update the API endpoints',
            'modify database structure',
        ];

        $contextTimes = [];

        foreach ($contextInputs as $input) {
            $startTime = microtime(true);
            $result = $this->recognizer->analyze($input, 'Laravel development response.', []);
            $processingTime = $result->getProcessingTime();

            if ($result->getProcessingPath() === 'context') {
                $contextTimes[] = $processingTime;
            }
        }

        if (! empty($contextTimes)) {
            $averageTime = array_sum($contextTimes) / count($contextTimes);
            $this->assertLessThan(50, $averageTime, 'Context analysis should be under 50ms on average');

            // All context analyses should be under 100ms
            foreach ($contextTimes as $time) {
                $this->assertLessThan(100, $time, 'Individual context analyses should be under 100ms');
            }
        }
    }

    #[Test]
    public function it_maintains_high_throughput_under_load()
    {
        $inputTypes = [
            'pattern' => ['yes', 'do it', 'make it', 'test it', 'another one'],
            'context' => ['enhance that', 'add more features', 'improve the system'],
            'complex' => ['What are the implications of using this approach in production?'],
        ];

        $totalInputs = 0;
        $allInputs = [];

        // Create large test dataset
        foreach ($inputTypes as $type => $inputs) {
            for ($i = 0; $i < 20; $i++) { // 20 of each type
                $allInputs[] = $inputs[array_rand($inputs)];
                $totalInputs++;
            }
        }

        // Add some context for realistic testing
        $this->contextTracker->addExchange(
            'Build web application',
            'Built a React application with Node.js backend and PostgreSQL database.'
        );

        $startTime = microtime(true);

        foreach ($allInputs as $input) {
            $result = $this->recognizer->analyze($input, 'Development response.');
            $this->assertInstanceOf(RecognitionResult::class, $result);
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $throughput = $totalInputs / ($totalTime / 1000); // Requests per second

        // Should maintain high throughput
        $this->assertGreaterThan(50, $throughput, 'Should process at least 50 requests per second');
        $this->assertLessThan(1000, $totalTime, 'Total processing should be under 1 second');
    }

    #[Test]
    public function it_scales_pattern_matching_efficiently()
    {
        $scalingTest = [
            10 => [], 50 => [], 100 => [], 500 => [],
        ];

        foreach (array_keys($scalingTest) as $inputCount) {
            $inputs = [];
            for ($i = 0; $i < $inputCount; $i++) {
                $inputs[] = 'do task '.($i % 10); // Create some variety
            }

            $startTime = microtime(true);

            foreach ($inputs as $input) {
                $this->patternMatcher->match($input);
            }

            $scalingTest[$inputCount] = (microtime(true) - $startTime) * 1000;
        }

        // Performance should scale linearly (or better)
        $this->assertLessThan(50, $scalingTest[100], '100 pattern matches should complete in under 50ms');
        $this->assertLessThan(250, $scalingTest[500], '500 pattern matches should complete in under 250ms');

        // Check scaling factor
        $scalingFactor = $scalingTest[500] / $scalingTest[100];
        $this->assertLessThan(10, $scalingFactor, 'Performance should not degrade significantly with scale');
    }

    #[Test]
    public function it_manages_memory_efficiently_during_long_sessions()
    {
        $initialMemory = memory_get_usage(true);

        // Simulate long conversation session
        for ($i = 0; $i < 100; $i++) {
            $input = "Task {$i}: Create component with feature set {$i}";
            $response = "Created Component{$i}.jsx with features: authentication, validation, and state management for user interaction patterns.";

            // Add to context tracker (simulates real usage)
            $this->contextTracker->addExchange($input, $response);

            // Analyze follow-ups
            $followUps = [
                'enhance it',
                'add more features',
                'test the component',
                'different approach',
            ];

            foreach ($followUps as $followUp) {
                $this->recognizer->analyze($followUp, $response);
            }
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be reasonable (under 10MB for this test)
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease, 'Memory usage should be reasonable');

        // Context tracker should limit its size
        $contextStats = $this->contextTracker->getContextStats();
        $this->assertLessThanOrEqual(50, $contextStats['topic_keywords'], 'Should limit topic keywords');
        $this->assertLessThanOrEqual(10, $contextStats['conversation_exchanges'], 'Should limit conversation history');
    }

    #[Test]
    public function it_maintains_accuracy_while_optimizing_speed()
    {
        $testCases = [
            // Clear follow-ups (should be fast and accurate)
            ['yes', true, 'pattern'],
            ['do it', true, 'pattern'],
            ['make another', true, 'pattern'],
            ['test it now', true, 'pattern'],
            ['improve that', true, 'pattern'],

            // Context-based follow-ups
            ['enhance the system', true, 'context'],
            ['add validation', true, 'context'],

            // Non-follow-ups (should be correctly identified)
            ['Create a completely new e-commerce platform', false, 'llm'],
            ['What is the weather in Tokyo today?', false, 'llm'],
        ];

        // Add context for context-based tests
        $this->contextTracker->addExchange(
            'Build system',
            'Built a comprehensive system with multiple components and services.'
        );

        $accuracyResults = [];
        $speedResults = [];

        foreach ($testCases as [$input, $expectedFollowUp, $expectedPath]) {
            $result = $this->recognizer->analyze($input, 'System response.');

            // Track accuracy
            $accuracyResults[] = ($result->isFollowUp() === $expectedFollowUp) ? 1 : 0;

            // Track speed by expected path
            if (! isset($speedResults[$expectedPath])) {
                $speedResults[$expectedPath] = [];
            }
            $speedResults[$expectedPath][] = $result->getProcessingTime();
        }

        // Should maintain high accuracy
        $accuracy = array_sum($accuracyResults) / count($accuracyResults);
        $this->assertGreaterThanOrEqual(0.8, $accuracy, 'Should maintain 80%+ accuracy');

        // Speed should meet targets by processing path
        if (isset($speedResults['pattern'])) {
            $avgPatternTime = array_sum($speedResults['pattern']) / count($speedResults['pattern']);
            $this->assertLessThan(5, $avgPatternTime, 'Pattern matching should be under 5ms');
        }

        if (isset($speedResults['context'])) {
            $avgContextTime = array_sum($speedResults['context']) / count($speedResults['context']);
            $this->assertLessThan(100, $avgContextTime, 'Context analysis should be under 100ms');
        }
    }

    #[Test]
    public function it_handles_concurrent_requests_efficiently()
    {
        // Simulate concurrent requests by processing multiple inputs rapidly
        $concurrentInputs = [
            'yes', 'do it', 'make another', 'test that', 'improve this',
            'enhance the feature', 'add more validation', 'update the system',
            'different approach needed', 'what about using microservices?',
        ];

        // Add context for variety
        $this->contextTracker->addExchange(
            'Create system',
            'Created a distributed system with microservices architecture.'
        );

        $processingTimes = [];
        $results = [];

        // Process all inputs in rapid succession (simulating concurrency)
        foreach ($concurrentInputs as $input) {
            $startTime = microtime(true);
            $result = $this->recognizer->analyze($input, 'Concurrent response.');
            $processingTime = (microtime(true) - $startTime) * 1000;

            $processingTimes[] = $processingTime;
            $results[] = $result;
        }

        // All requests should complete successfully
        $this->assertCount(count($concurrentInputs), $results);

        // Average processing time should be reasonable
        $avgTime = array_sum($processingTimes) / count($processingTimes);
        $this->assertLessThan(50, $avgTime, 'Average concurrent processing time should be under 50ms');

        // No individual request should take too long
        foreach ($processingTimes as $time) {
            $this->assertLessThan(200, $time, 'No individual request should take over 200ms');
        }
    }

    #[Test]
    public function it_optimizes_response_time_progression()
    {
        // Test that the system improves with usage (learns patterns)
        $testInput = 'enhance the functionality';
        $response = 'Enhanced the functionality with additional features.';

        // First analysis (cold start)
        $firstResult = $this->recognizer->analyze($testInput, $response);
        $firstTime = $firstResult->getProcessingTime();

        // Add to context to help future analyses
        $this->recognizer->updateContext($testInput, $response);

        // Subsequent analyses should be faster or at least not slower
        $subsequentTimes = [];
        for ($i = 0; $i < 5; $i++) {
            $result = $this->recognizer->analyze('enhance it further', $response);
            $subsequentTimes[] = $result->getProcessingTime();
        }

        $avgSubsequentTime = array_sum($subsequentTimes) / count($subsequentTimes);

        // Performance should not degrade significantly
        $this->assertLessThanOrEqual($firstTime * 2, $avgSubsequentTime,
            'Performance should not degrade significantly with usage');
    }

    #[Test]
    public function it_meets_overall_performance_targets()
    {
        // Comprehensive performance test combining all features
        $performanceTargets = [
            'pattern_matching_ms' => 1,
            'context_analysis_ms' => 50,
            'api_call_reduction_percent' => 75,
            'throughput_requests_per_second' => 100,
            'memory_limit_mb' => 50,
        ];

        $initialMemory = memory_get_usage(true);

        // Build realistic context
        for ($i = 0; $i < 5; $i++) {
            $this->contextTracker->addExchange(
                "Development task {$i}",
                "Completed development task {$i} with comprehensive implementation."
            );
        }

        // Mixed workload test
        $mixedInputs = array_merge(
            array_fill(0, 30, 'yes'),  // Pattern matches
            array_fill(0, 15, 'enhance the system'), // Context matches
            array_fill(0, 5, 'What about a completely different approach?') // LLM fallbacks
        );

        shuffle($mixedInputs);

        $startTime = microtime(true);
        $patternTimes = [];
        $contextTimes = [];

        foreach ($mixedInputs as $input) {
            $result = $this->recognizer->analyze($input, 'Mixed workload response.');

            if ($result->getProcessingPath() === 'pattern') {
                $patternTimes[] = $result->getProcessingTime();
            } elseif ($result->getProcessingPath() === 'context') {
                $contextTimes[] = $result->getProcessingTime();
            }
        }

        $totalTime = (microtime(true) - $startTime);
        $throughput = count($mixedInputs) / $totalTime;
        $finalMemory = memory_get_usage(true);
        $memoryUsed = ($finalMemory - $initialMemory) / (1024 * 1024); // MB

        // Get metrics
        $metrics = $this->recognizer->getMetrics();

        // Assert all performance targets are met
        if (! empty($patternTimes)) {
            $avgPatternTime = array_sum($patternTimes) / count($patternTimes);
            $this->assertLessThan($performanceTargets['pattern_matching_ms'], $avgPatternTime,
                'Pattern matching performance target not met');
        }

        if (! empty($contextTimes)) {
            $avgContextTime = array_sum($contextTimes) / count($contextTimes);
            $this->assertLessThan($performanceTargets['context_analysis_ms'], $avgContextTime,
                'Context analysis performance target not met');
        }

        $this->assertGreaterThan($performanceTargets['throughput_requests_per_second'], $throughput,
            'Throughput target not met');

        $this->assertGreaterThanOrEqual($performanceTargets['api_call_reduction_percent'], $metrics['api_call_reduction'],
            'API call reduction target not met');

        $this->assertLessThan($performanceTargets['memory_limit_mb'], $memoryUsed,
            'Memory usage target not met');
    }
}
