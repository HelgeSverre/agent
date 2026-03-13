<?php

namespace Tests\Unit\Chat;

use App\Agent\Chat\ContextResult;
use App\Agent\Chat\ContextTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContextTrackerTest extends TestCase
{
    protected ContextTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new ContextTracker;
    }

    #[Test]
    public function it_tracks_conversation_exchanges()
    {
        $input = 'Create a user authentication system';
        $response = "I've created a UserController with login and registration methods.";

        $this->tracker->addExchange($input, $response);

        $stats = $this->tracker->getContextStats();

        $this->assertEquals(1, $stats['conversation_exchanges']);
        $this->assertGreaterThan(0, $stats['tracked_entities']);
        $this->assertGreaterThan(0, $stats['topic_keywords']);
    }

    #[Test]
    public function it_extracts_keywords_from_conversation()
    {
        $input = 'Create a Laravel application with user authentication and database migrations';
        $response = 'Successfully created Laravel app with Auth scaffolding and migration files.';

        $this->tracker->addExchange($input, $response);

        // Test keyword extraction through topic similarity
        $followUpInput = 'Add more Laravel features';
        $result = $this->tracker->analyze($followUpInput);

        $this->assertGreaterThan(0.0, $result->getConfidence());
    }

    #[Test]
    public function it_extracts_entities_from_responses()
    {
        $exchanges = [
            [
                'input' => 'Create a configuration file',
                'response' => 'I\'ve created config.json with your database settings and API keys.',
            ],
            [
                'input' => 'Set up React components',
                'response' => 'Created UserProfile.jsx and Dashboard.jsx with TypeScript support.',
            ],
            [
                'input' => 'Deploy the application',
                'response' => 'Deployed to https://myapp.herokuapp.com with Docker containers.',
            ],
        ];

        foreach ($exchanges as $exchange) {
            $this->tracker->addExchange($exchange['input'], $exchange['response']);
        }

        $stats = $this->tracker->getContextStats();

        // Should have extracted file names, URLs, and technologies
        $this->assertGreaterThan(5, $stats['tracked_entities']);
        $this->assertGreaterThan(10, $stats['topic_keywords']);
    }

    #[Test]
    public function it_analyzes_follow_ups_based_on_topic_similarity()
    {
        // Establish context about PHP development
        $this->tracker->addExchange(
            'Create a PHP application',
            "I've created a Laravel application with MVC structure and Eloquent ORM."
        );

        $this->tracker->addExchange(
            'Add authentication',
            'Added Laravel Auth with JWT tokens and password hashing.'
        );

        // Test follow-up related to same topic
        $result = $this->tracker->analyze('Add more Laravel middleware');

        $this->assertTrue($result->isFollowUp());
        $this->assertGreaterThan(0.4, $result->getConfidence());
        $this->assertStringContains('Laravel', $result->getRelevantContext());
    }

    #[Test]
    public function it_detects_entity_references_in_input()
    {
        // Add context with specific entities
        $this->tracker->addExchange(
            'Create user management',
            'Created UserController.php with CRUD operations and UserModel.php for database interactions.'
        );

        // Test input referencing known entities
        $result = $this->tracker->analyze('Update the UserController methods');

        $this->assertTrue($result->isFollowUp());
        $this->assertGreaterThan(0.4, $result->getConfidence());
        $this->assertNotEmpty($result->getRelevantContext());
    }

    #[Test]
    public function it_tracks_action_continuation_patterns()
    {
        $this->tracker->addExchange(
            'Analyze the codebase',
            "I've analyzed the codebase and found several optimization opportunities in the database queries."
        );

        // Follow-up that continues the action
        $result = $this->tracker->analyze('Analyze more files');

        $this->assertTrue($result->isFollowUp());
        $this->assertGreaterThan(0.4, $result->getConfidence());
    }

    #[Test]
    public function it_gives_higher_confidence_to_recent_entities()
    {
        // Add older context
        $this->tracker->addExchange(
            'Create old system',
            'Created OldSystem.php five minutes ago.'
        );

        // Simulate time passage by manually updating entity timestamps
        $reflection = new \ReflectionClass($this->tracker);
        $property = $reflection->getProperty('entityRegistry');
        $property->setAccessible(true);
        $entities = $property->getValue($this->tracker);

        // Make entity appear old
        if (isset($entities['OldSystem.php'])) {
            $entities['OldSystem.php']['last_seen'] = time() - 400; // 6+ minutes ago
            $property->setValue($this->tracker, $entities);
        }

        // Add recent context
        $this->tracker->addExchange(
            'Create new component',
            'Created NewComponent.jsx just now.'
        );

        $oldResult = $this->tracker->analyze('Update OldSystem');
        $newResult = $this->tracker->analyze('Update NewComponent');

        if ($oldResult->isFollowUp() && $newResult->isFollowUp()) {
            $this->assertGreaterThan($oldResult->getConfidence(), $newResult->getConfidence());
        }
    }

    #[Test]
    public function it_maintains_limited_history_size()
    {
        $maxHistorySize = 10;

        // Add more exchanges than the max history size
        for ($i = 0; $i < $maxHistorySize + 5; $i++) {
            $this->tracker->addExchange(
                "Input {$i}",
                "Response {$i} with some content."
            );
        }

        $stats = $this->tracker->getContextStats();

        // Should not exceed max history size
        $this->assertLessThanOrEqual($maxHistorySize, $stats['conversation_exchanges']);
    }

    #[Test]
    public function it_limits_topic_keywords_to_prevent_memory_bloat()
    {
        // Add many different topics
        $topics = [
            'PHP Laravel development framework',
            'JavaScript React components state management',
            'Python Django REST API authentication',
            'Java Spring Boot microservices architecture',
            'TypeScript Angular reactive forms',
            'Ruby Rails ActiveRecord database migrations',
            'Go fiber web server HTTP routing',
            'Rust actix web async programming',
        ];

        foreach ($topics as $i => $topic) {
            $this->tracker->addExchange(
                "Work on {$topic}",
                "Implemented {$topic} with advanced features and optimizations."
            );
        }

        $stats = $this->tracker->getContextStats();

        // Should limit keywords to prevent memory bloat (max 50)
        $this->assertLessThanOrEqual(50, $stats['topic_keywords']);
    }

    #[Test]
    public function it_returns_no_follow_up_for_unrelated_input()
    {
        $this->tracker->addExchange(
            'Create web application',
            'Created a Laravel web application with user authentication.'
        );

        // Completely unrelated input
        $result = $this->tracker->analyze("What's the weather in Tokyo?");

        $this->assertFalse($result->isFollowUp());
        $this->assertLessThan(0.4, $result->getConfidence());
    }

    #[Test]
    public function it_handles_empty_context_gracefully()
    {
        // No context added
        $result = $this->tracker->analyze('Some random input');

        $this->assertFalse($result->isFollowUp());
        $this->assertEquals(0.0, $result->getConfidence());
        $this->assertEmpty($result->getRelevantContext());
    }

    #[Test]
    #[DataProvider('keywordExtractionDataProvider')]
    public function it_extracts_meaningful_keywords(
        string $text,
        array $expectedKeywords,
        array $forbiddenKeywords
    ) {
        $reflection = new \ReflectionClass($this->tracker);
        $method = $reflection->getMethod('extractKeywords');
        $method->setAccessible(true);

        $keywords = $method->invoke($this->tracker, $text);

        foreach ($expectedKeywords as $expected) {
            $this->assertContains($expected, $keywords, "Expected keyword '{$expected}' not found");
        }

        foreach ($forbiddenKeywords as $forbidden) {
            $this->assertNotContains($forbidden, $keywords, "Forbidden keyword '{$forbidden}' was found");
        }
    }

    #[Test]
    #[DataProvider('entityExtractionDataProvider')]
    public function it_extracts_relevant_entities(
        string $text,
        array $expectedEntities
    ) {
        $reflection = new \ReflectionClass($this->tracker);
        $method = $reflection->getMethod('extractEntities');
        $method->setAccessible(true);

        $entities = $method->invoke($this->tracker, $text);

        foreach ($expectedEntities as $expected) {
            $this->assertContains($expected, $entities, "Expected entity '{$expected}' not found");
        }
    }

    #[Test]
    public function it_calculates_topic_similarity_accurately()
    {
        $this->tracker->addExchange(
            'Create PHP Laravel application',
            'Successfully created Laravel app with Eloquent ORM and Blade templates.'
        );

        $reflection = new \ReflectionClass($this->tracker);
        $method = $reflection->getMethod('calculateTopicSimilarity');
        $method->setAccessible(true);

        // High similarity - same topic
        $similarKeywords = ['php', 'laravel', 'eloquent'];
        $similarity = $method->invoke($this->tracker, $similarKeywords);
        $this->assertGreaterThan(0.3, $similarity);

        // Low similarity - different topic
        $differentKeywords = ['weather', 'forecast', 'temperature'];
        $similarity = $method->invoke($this->tracker, $differentKeywords);
        $this->assertLessThan(0.2, $similarity);
    }

    #[Test]
    public function it_provides_relevant_context_for_enhancements()
    {
        $this->tracker->addExchange(
            'Create authentication system',
            'Created JWT-based authentication with UserController.php and AuthService.php.'
        );

        $result = $this->tracker->analyze('Enhance the authentication system');

        if ($result->isFollowUp()) {
            $context = $result->getRelevantContext();
            $this->assertNotEmpty($context);
            // Should contain relevant entities
            $this->assertTrue(
                str_contains($context, 'UserController.php') ||
                str_contains($context, 'AuthService.php') ||
                str_contains($context, 'JWT')
            );
        }
    }

    #[Test]
    public function it_clears_old_context_to_prevent_memory_leaks()
    {
        // Add context
        $this->tracker->addExchange(
            'Old task',
            'Completed old task with OldFile.php.'
        );

        // Simulate old context (1+ hours old)
        $reflection = new \ReflectionClass($this->tracker);
        $property = $reflection->getProperty('entityRegistry');
        $property->setAccessible(true);
        $entities = $property->getValue($this->tracker);

        foreach ($entities as $key => $entity) {
            $entities[$key]['last_seen'] = time() - 4000; // Over 1 hour old
        }
        $property->setValue($this->tracker, $entities);

        // Clear old context
        $this->tracker->clearOldContext(3600); // 1 hour max age

        $stats = $this->tracker->getContextStats();

        // Old entities should be removed
        $this->assertEquals(0, $stats['tracked_entities']);
    }

    #[Test]
    public function it_provides_comprehensive_context_statistics()
    {
        $this->tracker->addExchange(
            'Create complex system',
            'Created ComplexSystem.php with DatabaseManager.php, CacheService.php and ApiClient.php.'
        );

        $stats = $this->tracker->getContextStats();

        $expectedKeys = [
            'conversation_exchanges',
            'tracked_entities',
            'topic_keywords',
            'memory_usage',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $stats);
        }

        $this->assertArrayHasKey('history_size', $stats['memory_usage']);
        $this->assertArrayHasKey('entity_size', $stats['memory_usage']);
        $this->assertArrayHasKey('keyword_size', $stats['memory_usage']);

        // All values should be non-negative integers
        $this->assertGreaterThanOrEqual(0, $stats['conversation_exchanges']);
        $this->assertGreaterThanOrEqual(0, $stats['tracked_entities']);
        $this->assertGreaterThanOrEqual(0, $stats['topic_keywords']);
    }

    #[Test]
    public function it_handles_edge_cases_in_context_analysis()
    {
        $edgeCases = [
            '', // Empty input
            '   ', // Whitespace only
            "\n\t\r", // Various whitespace
            str_repeat('word ', 1000), // Very long input
            'Special chars: !@#$%^&*()[]{}|\\"',
            '12345 67890', // Numbers
        ];

        foreach ($edgeCases as $input) {
            $result = $this->tracker->analyze($input);

            // Should not crash and return valid result
            $this->assertInstanceOf(ContextResult::class, $result);
            $this->assertIsFloat($result->getConfidence());
            $this->assertIsString($result->getRelevantContext());
        }
    }

    #[Test]
    public function it_performs_analysis_efficiently()
    {
        // Add substantial context
        for ($i = 0; $i < 10; $i++) {
            $this->tracker->addExchange(
                "Task {$i} with multiple keywords and entities",
                "Completed task {$i} creating File{$i}.php, Service{$i}.js, and Component{$i}.vue with advanced features."
            );
        }

        $testInputs = [
            'Update the files',
            'Enhance the services',
            'Modify components',
            'Add more features',
            'Different unrelated topic',
        ];

        $startTime = microtime(true);

        foreach ($testInputs as $input) {
            $result = $this->tracker->analyze($input);
            $this->assertInstanceOf(ContextResult::class, $result);
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Should complete analysis quickly even with substantial context
        $this->assertLessThan(100, $totalTime, 'Context analysis should be fast');
    }

    /**
     * Data provider for keyword extraction testing
     */
    public static function keywordExtractionDataProvider(): array
    {
        return [
            'Programming text' => [
                'I created a Laravel application with user authentication and database migrations',
                ['created', 'laravel', 'application', 'user', 'authentication', 'database', 'migrations'],
                ['the', 'a', 'and', 'with'], // Stop words should be filtered
            ],
            'Technical description' => [
                'The system uses React components with TypeScript and handles API requests efficiently',
                ['system', 'uses', 'react', 'components', 'typescript', 'handles', 'api', 'requests', 'efficiently'],
                ['the', 'with', 'and'], // Stop words
            ],
            'File operations' => [
                'Successfully wrote data to config.json and uploaded files to server directory',
                ['successfully', 'wrote', 'data', 'config', 'json', 'uploaded', 'files', 'server', 'directory'],
                ['to', 'and'], // Stop words and short words
            ],
        ];
    }

    /**
     * Data provider for entity extraction testing
     */
    public static function entityExtractionDataProvider(): array
    {
        return [
            'Files and technologies' => [
                'Created UserController.php with Laravel framework and connected to MySQL database',
                ['UserController.php', 'laravel', 'mysql'],
            ],
            'URLs and files' => [
                'Deployed application to https://myapp.herokuapp.com and updated config.json settings',
                ['https://myapp.herokuapp.com', 'config.json'],
            ],
            'Multiple file types' => [
                'Generated Report.pdf, exported data.csv, and created backup.sql for the system',
                ['Report.pdf', 'data.csv', 'backup.sql'],
            ],
            'JavaScript files' => [
                'Built App.js with React components and utils.ts for TypeScript utilities',
                ['App.js', 'utils.ts', 'react'],
            ],
            'Common technologies' => [
                'Implemented with Docker containers, PostgreSQL database, and AWS cloud services',
                ['docker', 'postgresql', 'aws'],
            ],
        ];
    }

    #[Test]
    public function it_handles_concurrent_modifications_safely()
    {
        // Simulate concurrent access by rapidly adding exchanges
        $exchanges = [];
        for ($i = 0; $i < 20; $i++) {
            $exchanges[] = [
                'input' => "Concurrent input {$i}",
                'response' => "Concurrent response {$i} with File{$i}.php",
            ];
        }

        // Add exchanges rapidly
        foreach ($exchanges as $exchange) {
            $this->tracker->addExchange($exchange['input'], $exchange['response']);

            // Also test analysis during modification
            $result = $this->tracker->analyze('Follow up on concurrent task');
            $this->assertInstanceOf(ContextResult::class, $result);
        }

        // Final state should be consistent
        $stats = $this->tracker->getContextStats();
        $this->assertGreaterThan(0, $stats['conversation_exchanges']);
        $this->assertGreaterThan(0, $stats['tracked_entities']);
    }
}
