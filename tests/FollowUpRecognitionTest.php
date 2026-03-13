<?php

use App\Agent\Chat\FollowUpRecognizer;
use App\Agent\Chat\PatternMatcher;

/**
 * Test script for the hybrid follow-up recognition system
 * Run with: php tests/FollowUpRecognitionTest.php
 */

require_once __DIR__.'/../vendor/autoload.php';

class FollowUpRecognitionTest
{
    private FollowUpRecognizer $recognizer;

    private array $testCases;

    public function __construct()
    {
        $this->recognizer = new FollowUpRecognizer;
        $this->initializeTestCases();
    }

    public function runTests(): void
    {
        echo "🚀 Testing Hybrid Follow-up Recognition System\n";
        echo str_repeat('=', 60)."\n";

        $totalTests = count($this->testCases);
        $passedTests = 0;
        $performanceResults = [];

        foreach ($this->testCases as $index => $testCase) {
            echo "\n".($index + 1).". Testing: \"{$testCase['input']}\"\n";

            $startTime = microtime(true);
            $result = $this->recognizer->analyze(
                $testCase['input'],
                $testCase['previous_response'],
                $testCase['conversation_history'] ?? []
            );
            $processingTime = (microtime(true) - $startTime) * 1000;

            $performanceResults[] = [
                'processing_time' => $processingTime,
                'path' => $result->getProcessingPath(),
                'confidence' => $result->getConfidence(),
            ];

            // Check if result matches expectation
            $expected = $testCase['expected_follow_up'];
            $actual = $result->isFollowUp();
            $confidence = $result->getConfidence();
            $path = $result->getProcessingPath();

            if ($actual === $expected) {
                echo '   ✅ PASS - Follow-up: '.($actual ? 'Yes' : 'No').
                     ' | Confidence: '.round($confidence * 100, 1).'%'.
                     " | Path: {$path}".
                     ' | Time: '.round($processingTime, 2)."ms\n";
                $passedTests++;
            } else {
                echo '   ❌ FAIL - Expected: '.($expected ? 'Yes' : 'No').
                     ', Got: '.($actual ? 'Yes' : 'No').
                     ' | Confidence: '.round($confidence * 100, 1).'%'.
                     " | Path: {$path}\n";
            }

            if ($result->isFollowUp()) {
                echo "   📝 Enhanced: \"{$result->getEnhancedInput()}\"\n";
            }

            // Update context for next test
            if ($testCase['input'] !== $this->testCases[0]['input']) {
                $this->recognizer->updateContext($testCase['input'], 'Mock response for context building');
            }
        }

        $this->showResults($passedTests, $totalTests, $performanceResults);
        $this->showSystemMetrics();
    }

    private function initializeTestCases(): void
    {
        $this->testCases = [
            // Pattern matching tests (should be FAST)
            [
                'input' => 'yes, do it',
                'previous_response' => 'I can create a PHP class for you. Would you like me to proceed?',
                'expected_follow_up' => true,
            ],
            [
                'input' => 'test it',
                'previous_response' => 'I created a new user registration function in UserController.php',
                'expected_follow_up' => true,
            ],
            [
                'input' => 'make another one',
                'previous_response' => 'I generated a REST API endpoint for user authentication',
                'expected_follow_up' => true,
            ],
            [
                'input' => 'fix that bug',
                'previous_response' => 'I found an issue in the payment processing logic',
                'expected_follow_up' => true,
            ],
            [
                'input' => 'what about using Redis?',
                'previous_response' => 'The caching system could be improved with better performance',
                'expected_follow_up' => true,
            ],
            [
                'input' => 'no, skip that',
                'previous_response' => 'Should I add error handling to the database connection?',
                'expected_follow_up' => true,
            ],

            // Context analysis tests
            [
                'input' => 'how does it work?',
                'previous_response' => 'I implemented a JWT authentication system with token refresh',
                'expected_follow_up' => true,
            ],
            [
                'input' => 'add validation',
                'previous_response' => 'The user input form is complete with basic fields',
                'expected_follow_up' => true,
            ],

            // Should NOT be follow-ups (new tasks)
            [
                'input' => 'Create a new Laravel application with user authentication',
                'previous_response' => 'I fixed the database connection issue in config/database.php',
                'expected_follow_up' => false,
            ],
            [
                'input' => 'Analyze the performance of this SQL query: SELECT * FROM users WHERE email = ?',
                'previous_response' => 'The shopping cart functionality has been implemented successfully',
                'expected_follow_up' => false,
            ],
            [
                'input' => 'Write a Python script to process CSV files',
                'previous_response' => 'The PHP API endpoints are working correctly',
                'expected_follow_up' => false,
            ],
        ];
    }

    private function showResults(int $passed, int $total, array $performanceResults): void
    {
        echo "\n".str_repeat('=', 60)."\n";
        echo "📊 TEST RESULTS\n";
        echo str_repeat('-', 30)."\n";
        echo "Passed: {$passed}/{$total} (".round(($passed / $total) * 100, 1)."%)\n";

        // Performance analysis
        $avgTime = array_sum(array_column($performanceResults, 'processing_time')) / count($performanceResults);
        $patternCount = count(array_filter($performanceResults, fn ($r) => $r['path'] === 'pattern'));
        $contextCount = count(array_filter($performanceResults, fn ($r) => $r['path'] === 'context'));
        $llmCount = count(array_filter($performanceResults, fn ($r) => $r['path'] === 'llm'));

        echo "\n⚡ PERFORMANCE METRICS\n";
        echo str_repeat('-', 30)."\n";
        echo 'Average processing time: '.round($avgTime, 2)."ms\n";
        echo "Pattern path usage: {$patternCount}/".count($performanceResults).' ('.round(($patternCount / count($performanceResults)) * 100, 1)."%)\n";
        echo "Context path usage: {$contextCount}/".count($performanceResults).' ('.round(($contextCount / count($performanceResults)) * 100, 1)."%)\n";
        echo "LLM path usage: {$llmCount}/".count($performanceResults).' ('.round(($llmCount / count($performanceResults)) * 100, 1)."%)\n";

        $fastPaths = $patternCount + $contextCount;
        $apiReduction = round(($fastPaths / count($performanceResults)) * 100, 1);
        echo "API call reduction: {$apiReduction}%\n";

        // Performance targets
        echo "\n🎯 PERFORMANCE TARGETS\n";
        echo str_repeat('-', 30)."\n";
        echo "Target response time: <100ms for 90% of cases\n";
        echo "Target API reduction: >75%\n";

        $fastProcessing = count(array_filter($performanceResults, fn ($r) => $r['processing_time'] < 100));
        $fastPercentage = round(($fastProcessing / count($performanceResults)) * 100, 1);

        echo "Actual fast processing: {$fastProcessing}/".count($performanceResults)." ({$fastPercentage}%)\n";
        echo "Actual API reduction: {$apiReduction}%\n";

        // Status
        $timeTarget = $fastPercentage >= 90 ? '✅' : '❌';
        $apiTarget = $apiReduction >= 75 ? '✅' : '❌';

        echo "\nTargets met:\n";
        echo "{$timeTarget} Response time: {$fastPercentage}% < 100ms (target: 90%)\n";
        echo "{$apiTarget} API reduction: {$apiReduction}% (target: 75%)\n";
    }

    private function showSystemMetrics(): void
    {
        echo "\n🔧 SYSTEM METRICS\n";
        echo str_repeat('-', 30)."\n";

        $metrics = $this->recognizer->getMetrics();
        foreach ($metrics as $key => $value) {
            echo ucfirst(str_replace('_', ' ', $key)).': '.(is_float($value) ? round($value, 2) : $value)."\n";
        }
    }

    public static function testPatternMatcherDirectly(): void
    {
        echo "\n🧪 PATTERN MATCHER DIRECT TESTS\n";
        echo str_repeat('=', 40)."\n";

        $matcher = new PatternMatcher;

        $testPatterns = [
            'yes, do it' => 'command_continuation',
            'test it now' => 'pronoun_reference',
            'another one please' => 'reference_continuation',
            'what about Redis?' => 'clarification_request',
            'no, skip that' => 'negation_continuation',
        ];

        foreach ($testPatterns as $input => $expectedType) {
            $result = $matcher->match($input);
            $status = ($result->isMatch() && $result->getPatternType() === $expectedType) ? '✅' : '❌';

            echo "{$status} \"{$input}\" -> ";
            if ($result->isMatch()) {
                echo $result->getPatternType().' ('.round($result->getConfidence() * 100, 1)."%)\n";
            } else {
                echo "No match\n";
            }
        }
    }
}

// Run the tests
if (php_sapi_name() === 'cli') {
    $tester = new FollowUpRecognitionTest;
    $tester->runTests();
    FollowUpRecognitionTest::testPatternMatcherDirectly();
}
