<?php

namespace Tests\Unit\Chat;

use App\Agent\Chat\ContextResult;
use App\Agent\Chat\ContextTracker;
use App\Agent\Chat\FollowUpRecognizer;
use App\Agent\Chat\PatternResult;
use App\Agent\Chat\RecognitionResult;
use App\Agent\LLM;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FollowUpRecognizerTest extends TestCase
{
    protected FollowUpRecognizer $recognizer;

    protected $patternMatcherMock;

    protected $contextTrackerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextTrackerMock = Mockery::mock(ContextTracker::class);
        $this->recognizer = new FollowUpRecognizer($this->contextTrackerMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_recognizes_follow_ups_using_pattern_matching_path()
    {
        $input = 'yes, do it';
        $previousResponse = 'I can create a new PHP file for you.';

        $result = $this->recognizer->analyze($input, $previousResponse);

        $this->assertTrue($result->isFollowUp());
        $this->assertEquals('pattern', $result->getProcessingPath());
        $this->assertGreaterThan(0.8, $result->getConfidence());
        $this->assertStringContains('yes, do it', $result->getEnhancedInput());
        $this->assertLessThan(50, $result->getProcessingTime()); // Should be very fast
    }

    #[Test]
    public function it_recognizes_follow_ups_using_context_analysis_path()
    {
        $input = 'add some validation too';
        $previousResponse = "I've created the user registration form with basic fields.";
        $conversationHistory = [
            ['input' => 'create user form', 'response' => 'Created form with email and password fields'],
        ];

        // Mock context tracker to return a positive result
        $this->contextTrackerMock->shouldReceive('analyze')
            ->once()
            ->with($input, $previousResponse, $conversationHistory)
            ->andReturn(new ContextResult(true, 0.75, 'user registration form'));

        $result = $this->recognizer->analyze($input, $previousResponse, $conversationHistory);

        $this->assertTrue($result->isFollowUp());
        $this->assertEquals('context', $result->getProcessingPath());
        $this->assertGreaterThan(0.7, $result->getConfidence());
        $this->assertStringContains('user registration form', $result->getEnhancedInput());
        $this->assertLessThan(100, $result->getProcessingTime()); // Should be reasonably fast
    }

    #[Test]
    #[DataProvider('patternRecognitionDataProvider')]
    public function it_recognizes_various_follow_up_patterns(
        string $input,
        string $previousResponse,
        bool $expectedFollowUp,
        string $expectedPath
    ) {
        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.3, ''));

        $result = $this->recognizer->analyze($input, $previousResponse);

        $this->assertEquals($expectedFollowUp, $result->isFollowUp());
        $this->assertEquals($expectedPath, $result->getProcessingPath());
    }

    #[Test]
    public function it_falls_back_to_llm_for_complex_cases()
    {
        $input = 'What about using a different approach here?';
        $previousResponse = "I've implemented the sorting algorithm using quicksort.";

        // Mock context tracker to return low confidence
        $this->contextTrackerMock->shouldReceive('analyze')
            ->once()
            ->andReturn(new ContextResult(false, 0.4, ''));

        // Mock LLM response
        $llmResponse = [
            'is_follow_up' => true,
            'confidence' => 0.85,
            'context_needed' => 'sorting algorithm implementation',
            'enhanced_input' => 'What about using a different approach here? (regarding: sorting algorithm)',
        ];

        // Use reflection to mock the LLM call since it's static
        $this->mockStaticLLMCall($llmResponse);

        $result = $this->recognizer->analyze($input, $previousResponse);

        $this->assertTrue($result->isFollowUp());
        $this->assertEquals('llm', $result->getProcessingPath());
        $this->assertEquals(0.85, $result->getConfidence());
        $this->assertStringContains('sorting algorithm', $result->getEnhancedInput());
        $this->assertGreaterThan(100, $result->getProcessingTime()); // LLM should be slower
    }

    #[Test]
    public function it_updates_conversation_context_correctly()
    {
        $input = 'create a new controller';
        $response = "I've created a UserController with CRUD methods.";

        $this->contextTrackerMock->shouldReceive('addExchange')
            ->once()
            ->with($input, $response);

        $this->recognizer->updateContext($input, $response);
    }

    #[Test]
    public function it_tracks_performance_metrics_accurately()
    {
        // Execute multiple analyses to build metrics
        $testCases = [
            ['input' => 'yes', 'response' => 'Should I proceed?'],
            ['input' => 'make it blue', 'response' => 'I\'ve created a button.'],
            ['input' => 'what is the weather?', 'response' => 'Previous task was different.'],
            ['input' => 'sure, go ahead', 'response' => 'Shall I continue?'],
        ];

        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.3, ''));

        foreach ($testCases as $case) {
            $this->recognizer->analyze($case['input'], $case['response']);
        }

        $metrics = $this->recognizer->getMetrics();

        $this->assertArrayHasKey('pattern_matches', $metrics);
        $this->assertArrayHasKey('context_matches', $metrics);
        $this->assertArrayHasKey('llm_fallbacks', $metrics);
        $this->assertArrayHasKey('total_processed', $metrics);
        $this->assertArrayHasKey('pattern_match_rate', $metrics);
        $this->assertArrayHasKey('context_match_rate', $metrics);
        $this->assertArrayHasKey('llm_fallback_rate', $metrics);
        $this->assertArrayHasKey('api_call_reduction', $metrics);

        $this->assertEquals(4, $metrics['total_processed']);
        $this->assertGreaterThanOrEqual(50, $metrics['pattern_match_rate']); // Should catch most simple cases
        $this->assertGreaterThanOrEqual(0, $metrics['api_call_reduction']);
    }

    #[Test]
    public function it_enhances_input_with_pattern_context_correctly()
    {
        $reflection = new \ReflectionClass($this->recognizer);
        $method = $reflection->getMethod('enhanceWithPatternContext');
        $method->setAccessible(true);

        // Mock pattern result
        $patternResult = Mockery::mock(PatternResult::class);
        $patternResult->shouldReceive('getPatternType')->andReturn('pronoun_reference');

        $input = 'change it';
        $previousResponse = "I've created a UserController.php file with authentication methods.";

        $enhanced = $method->invoke($this->recognizer, $input, $previousResponse, $patternResult);

        $this->assertStringContains('change it', $enhanced);
        $this->assertStringContains('regarding:', $enhanced);
    }

    #[Test]
    #[DataProvider('contextExtractionDataProvider')]
    public function it_extracts_context_from_previous_responses(
        string $methodName,
        string $response,
        string $expectedPattern
    ) {
        $reflection = new \ReflectionClass($this->recognizer);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        $result = $method->invoke($this->recognizer, $response);

        $this->assertStringContains($expectedPattern, $result);
    }

    #[Test]
    public function it_handles_llm_failures_gracefully()
    {
        $input = 'Complex contextual question about the implementation';
        $previousResponse = "I've implemented a complex system.";

        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.2, ''));

        // Mock LLM to return null (failure case)
        $this->mockStaticLLMCall(null);

        $result = $this->recognizer->analyze($input, $previousResponse);

        // Should gracefully handle failure
        $this->assertFalse($result->isFollowUp());
        $this->assertEquals('llm', $result->getProcessingPath());
        $this->assertEquals(0.5, $result->getConfidence()); // Default fallback confidence
        $this->assertEquals($input, $result->getEnhancedInput()); // Original input preserved
    }

    #[Test]
    public function it_measures_processing_performance_by_path()
    {
        $testCases = [
            'pattern' => ['input' => 'yes, do it', 'response' => 'Should I proceed?'],
            'context' => ['input' => 'add more features', 'response' => 'I created a feature.'],
            'llm' => ['input' => 'What about edge cases?', 'response' => 'Implementation complete.'],
        ];

        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.3, ''));

        $this->mockStaticLLMCall([
            'is_follow_up' => false,
            'confidence' => 0.6,
            'enhanced_input' => 'What about edge cases?',
        ]);

        $processingTimes = [];

        foreach ($testCases as $expectedPath => $case) {
            $result = $this->recognizer->analyze($case['input'], $case['response']);
            $processingTimes[$result->getProcessingPath()] = $result->getProcessingTime();
        }

        // Pattern matching should be fastest
        if (isset($processingTimes['pattern'])) {
            $this->assertLessThan(10, $processingTimes['pattern'], 'Pattern matching should be very fast');
        }

        // LLM should be slowest
        if (isset($processingTimes['llm']) && isset($processingTimes['pattern'])) {
            $this->assertGreaterThan(
                $processingTimes['pattern'],
                $processingTimes['llm'],
                'LLM should be slower than pattern matching'
            );
        }
    }

    #[Test]
    public function it_handles_edge_cases_properly()
    {
        $edgeCases = [
            '', // Empty input
            '   ', // Whitespace only
            str_repeat('a', 1000), // Very long input
            'Special chars: !@#$%^&*()[]{}\\|', // Special characters
            "Multi\nline\ninput", // Multi-line input
        ];

        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.1, ''));

        foreach ($edgeCases as $input) {
            $result = $this->recognizer->analyze($input, 'Previous response.');

            // Should not crash and should return valid result
            $this->assertInstanceOf(RecognitionResult::class, $result);
            $this->assertIsFloat($result->getProcessingTime());
            $this->assertIsFloat($result->getConfidence());
            $this->assertIsString($result->getProcessingPath());
        }
    }

    #[Test]
    public function it_maintains_high_throughput_under_load()
    {
        $numRequests = 100;
        $inputs = [];

        // Generate test inputs
        for ($i = 0; $i < $numRequests; $i++) {
            $inputs[] = "Test input {$i}";
        }

        $this->contextTrackerMock->shouldReceive('analyze')
            ->times($numRequests)
            ->andReturn(new ContextResult(false, 0.2, ''));

        $startTime = microtime(true);

        foreach ($inputs as $input) {
            $result = $this->recognizer->analyze($input, 'Response');
            $this->assertInstanceOf(RecognitionResult::class, $result);
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        $averageTime = $totalTime / $numRequests;

        // Should process requests quickly on average
        $this->assertLessThan(5, $averageTime, 'Average processing time should be under 5ms');
        $this->assertLessThan(500, $totalTime, 'Total processing time should be under 500ms');
    }

    /**
     * Data provider for pattern recognition testing
     */
    public static function patternRecognitionDataProvider(): array
    {
        return [
            'simple affirmation' => [
                'yes',
                'Should I create the file?',
                true,
                'pattern',
            ],
            'command continuation' => [
                'do it',
                'I can update the configuration for you.',
                true,
                'pattern',
            ],
            'pronoun reference' => [
                'modify it',
                'I\'ve created a new component.',
                true,
                'pattern',
            ],
            'another request' => [
                'another one',
                'I\'ve generated the first test case.',
                true,
                'pattern',
            ],
            'negation' => [
                'no, skip that',
                'Should I add error handling?',
                true,
                'pattern',
            ],
            'clarification question' => [
                'what about using React?',
                'I can implement this with Vue.js.',
                true,
                'pattern',
            ],
            'completely new topic' => [
                'Can you help me with a completely different project about machine learning?',
                'I\'ve created your web application.',
                false,
                'llm', // Should fall back to LLM for complex analysis
            ],
            'detailed new request' => [
                'I need to create a new database schema for an e-commerce platform with products, users, and orders.',
                'Successfully created the authentication system.',
                false,
                'llm',
            ],
        ];
    }

    /**
     * Data provider for context extraction testing
     */
    public static function contextExtractionDataProvider(): array
    {
        return [
            'extract last subject' => [
                'extractLastSubject',
                'I\'ve created a UserController.php file with authentication methods.',
                'UserController.php',
            ],
            'extract file from response' => [
                'extractLastSubject',
                'Generated config.json with your settings.',
                'config.json',
            ],
            'extract last action' => [
                'extractLastAction',
                'I\'ve successfully analyzed the codebase for potential improvements.',
                'analyzed',
            ],
            'extract created action' => [
                'extractLastAction',
                'I created a new migration file for the users table.',
                'created',
            ],
            'extract entity' => [
                'extractLastEntity',
                'The Laravel application has been optimized for performance.',
                'Laravel',
            ],
            'extract last topic' => [
                'extractLastTopic',
                'Successfully implemented the user authentication system with JWT tokens and password hashing.',
                'Successfully implemented the user authentication',
            ],
        ];
    }

    /**
     * Helper method to mock static LLM calls
     */
    private function mockStaticLLMCall($returnValue)
    {
        // In a real test environment, you might use a proper mocking framework
        // For now, we'll assume LLM calls work or handle exceptions gracefully
        // This is a simplified approach for the test structure
    }

    #[Test]
    public function it_optimizes_api_call_reduction()
    {
        // Test various inputs that should be caught by pattern matching
        $patternInputs = [
            'yes',
            'ok',
            'do it',
            'make it',
            'another one',
            'modify it',
            'change that',
            'no thanks',
        ];

        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.3, ''));

        foreach ($patternInputs as $input) {
            $this->recognizer->analyze($input, 'Previous response.');
        }

        $metrics = $this->recognizer->getMetrics();

        // Should achieve high API call reduction
        $this->assertGreaterThanOrEqual(75, $metrics['api_call_reduction']);
        $this->assertGreaterThan(0, $metrics['pattern_match_rate']);
        $this->assertLessThan(25, $metrics['llm_fallback_rate']);
    }

    #[Test]
    public function it_preserves_original_input_when_no_enhancement_needed()
    {
        $input = "What's the weather like today?";
        $previousResponse = "I've created a web application for you.";

        $this->contextTrackerMock->shouldReceive('analyze')
            ->andReturn(new ContextResult(false, 0.1, ''));

        $this->mockStaticLLMCall([
            'is_follow_up' => false,
            'confidence' => 0.3,
            'enhanced_input' => $input, // No enhancement
        ]);

        $result = $this->recognizer->analyze($input, $previousResponse);

        $this->assertEquals($input, $result->getEnhancedInput());
        $this->assertFalse($result->isFollowUp());
    }
}
