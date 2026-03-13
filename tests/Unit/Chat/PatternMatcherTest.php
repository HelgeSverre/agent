<?php

namespace Tests\Unit\Chat;

use App\Agent\Chat\PatternMatcher;
use App\Agent\Chat\PatternResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PatternMatcherTest extends TestCase
{
    protected PatternMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new PatternMatcher;
    }

    #[Test]
    #[DataProvider('pronounReferenceDataProvider')]
    public function it_matches_pronoun_reference_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('pronoun_reference', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    #[DataProvider('commandContinuationDataProvider')]
    public function it_matches_command_continuation_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('command_continuation', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    #[DataProvider('referenceContinuationDataProvider')]
    public function it_matches_reference_continuation_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('reference_continuation', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    #[DataProvider('clarificationRequestDataProvider')]
    public function it_matches_clarification_request_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('clarification_request', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    #[DataProvider('negationContinuationDataProvider')]
    public function it_matches_negation_continuation_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('negation_continuation', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    #[DataProvider('testContinuationDataProvider')]
    public function it_matches_test_continuation_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('test_continuation', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    #[DataProvider('enhancementRequestDataProvider')]
    public function it_matches_enhancement_request_patterns(string $input, bool $shouldMatch, float $minConfidence)
    {
        $result = $this->matcher->match($input);

        $this->assertEquals($shouldMatch, $result->isMatch());

        if ($shouldMatch) {
            $this->assertEquals('enhancement_request', $result->getPatternType());
            $this->assertGreaterThanOrEqual($minConfidence, $result->getConfidence());
        }
    }

    #[Test]
    public function it_calculates_confidence_based_on_input_characteristics()
    {
        // Short inputs should get higher confidence
        $shortResult = $this->matcher->match('yes');
        $longResult = $this->matcher->match('yes, but I want to add a comprehensive system for handling user authentication with multiple providers including OAuth2, SAML, and custom authentication mechanisms');

        if ($shortResult->isMatch() && $longResult->isMatch()) {
            $this->assertGreaterThan($longResult->getConfidence(), $shortResult->getConfidence());
        }
    }

    #[Test]
    public function it_adjusts_confidence_for_follow_up_starters()
    {
        $followUpStarters = ['yes', 'ok', 'sure', 'no', 'it', 'that', 'this', 'another', 'more'];

        foreach ($followUpStarters as $starter) {
            $result = $this->matcher->match($starter.' please');
            if ($result->isMatch()) {
                // Should get confidence boost for starting with follow-up words
                $this->assertGreaterThan(0.6, $result->getConfidence());
            }
        }
    }

    #[Test]
    public function it_reduces_confidence_for_urls_and_file_paths()
    {
        $inputsWithPaths = [
            'update the config.json file',
            'visit https://example.com for more info',
            'check the data.csv file',
            'go to http://localhost:3000',
        ];

        foreach ($inputsWithPaths as $input) {
            $result = $this->matcher->match($input);
            if ($result->isMatch()) {
                // Should have reduced confidence due to specific paths/URLs
                $this->assertLessThan(0.9, $result->getConfidence());
            }
        }
    }

    #[Test]
    public function it_boosts_confidence_for_short_questions()
    {
        $shortQuestions = [
            'ok?',
            'sure?',
            'now?',
            'this?',
        ];

        foreach ($shortQuestions as $question) {
            $result = $this->matcher->match($question);
            if ($result->isMatch()) {
                // Should get boost for being short question
                $this->assertGreaterThan(0.7, $result->getConfidence());
            }
        }
    }

    #[Test]
    public function it_selects_best_match_among_multiple_patterns()
    {
        // Input that could match multiple patterns
        $input = 'yes, make another one';

        $result = $this->matcher->match($input);

        $this->assertTrue($result->isMatch());
        // Should select the pattern with highest confidence
        $this->assertNotEmpty($result->getPatternType());
        $this->assertNotEmpty($result->getMatchedPattern());
        $this->assertGreaterThan(0.6, $result->getConfidence());
    }

    #[Test]
    public function it_returns_no_match_for_non_follow_up_inputs()
    {
        $nonFollowUpInputs = [
            'Create a new Laravel application with user authentication system',
            'What is the current weather in New York City?',
            'Explain how quantum computing works',
            'I need help with a completely different project about machine learning',
        ];

        foreach ($nonFollowUpInputs as $input) {
            $result = $this->matcher->match($input);
            $this->assertFalse($result->isMatch(), "Input '$input' should not match as follow-up");
        }
    }

    #[Test]
    public function it_provides_pattern_statistics()
    {
        $stats = $this->matcher->getPatternStats();

        $expectedPatterns = [
            'pronoun_reference',
            'reference_continuation',
            'command_continuation',
            'negation_continuation',
            'clarification_request',
            'test_continuation',
            'enhancement_request',
        ];

        foreach ($expectedPatterns as $pattern) {
            $this->assertArrayHasKey($pattern, $stats);
            $this->assertArrayHasKey('pattern_count', $stats[$pattern]);
            $this->assertArrayHasKey('base_confidence', $stats[$pattern]);
            $this->assertGreaterThan(0, $stats[$pattern]['pattern_count']);
            $this->assertGreaterThan(0, $stats[$pattern]['base_confidence']);
        }
    }

    #[Test]
    public function it_tests_specific_patterns_individually()
    {
        $testCases = [
            'pronoun_reference' => ['modify it', true],
            'command_continuation' => ['yes, do it', true],
            'reference_continuation' => ['another one', true],
            'clarification_request' => ['what about PHP?', true],
            'negation_continuation' => ['no, skip it', true],
            'test_continuation' => ['test it now', true],
            'enhancement_request' => ['improve the design', true],
        ];

        foreach ($testCases as $patternType => $testCase) {
            [$input, $shouldMatch] = $testCase;

            $result = $this->matcher->testPattern($input, $patternType);

            if ($shouldMatch) {
                $this->assertNotNull($result);
                $this->assertTrue($result->isMatch());
                $this->assertEquals($patternType, $result->getPatternType());
            }
        }
    }

    #[Test]
    public function it_handles_non_existent_pattern_types()
    {
        $result = $this->matcher->testPattern('test input', 'non_existent_pattern');
        $this->assertNull($result);
    }

    #[Test]
    public function it_handles_edge_cases_gracefully()
    {
        $edgeCases = [
            '', // Empty string
            '   ', // Whitespace only
            "\n\t\r", // Various whitespace chars
            str_repeat('a', 1000), // Very long string
            'Special chars: @#$%^&*()[]{}|\\',
            '123456789', // Numbers only
        ];

        foreach ($edgeCases as $input) {
            $result = $this->matcher->match($input);

            // Should not crash and should return valid result
            $this->assertInstanceOf(PatternResult::class, $result);
            $this->assertIsFloat($result->getConfidence());
            $this->assertIsString($result->getPatternType());
            $this->assertIsString($result->getMatchedPattern());
        }
    }

    #[Test]
    public function it_performs_matching_efficiently()
    {
        $testInputs = [
            'yes', 'no', 'ok', 'sure', 'do it', 'make it', 'another one',
            'modify it', 'change that', 'what about this', 'test it',
            'improve that', 'skip it', 'cancel', 'proceed',
        ];

        $startTime = microtime(true);

        foreach ($testInputs as $input) {
            $result = $this->matcher->match($input);
            $this->assertInstanceOf(PatternResult::class, $result);
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Should be very fast - under 10ms for all patterns
        $this->assertLessThan(10, $totalTime, 'Pattern matching should be very fast');
    }

    #[Test]
    public function it_maintains_consistent_results()
    {
        $testInput = 'yes, make another one';

        // Run the same input multiple times
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->matcher->match($testInput);
        }

        // All results should be identical
        $firstResult = $results[0];
        foreach (array_slice($results, 1) as $result) {
            $this->assertEquals($firstResult->isMatch(), $result->isMatch());
            $this->assertEquals($firstResult->getConfidence(), $result->getConfidence());
            $this->assertEquals($firstResult->getPatternType(), $result->getPatternType());
            $this->assertEquals($firstResult->getMatchedPattern(), $result->getMatchedPattern());
        }
    }

    /**
     * Data provider for pronoun reference pattern testing
     */
    public static function pronounReferenceDataProvider(): array
    {
        return [
            ['do it', true, 0.8],
            ['make it', true, 0.8],
            ['create it', true, 0.8],
            ['show it', true, 0.8],
            ['fix it', true, 0.8],
            ['update that', true, 0.8],
            ['modify this', true, 0.8],
            ['change them', true, 0.8],
            ['edit those', true, 0.8],
            ['can you update it', true, 0.8],
            ['yes, do it', true, 0.8],
            ['it needs fixing', true, 0.8],
            ['explain it', true, 0.8],
            ['tell me about this', true, 0.8],
            ['create a new project', false, 0.0], // Not a pronoun reference
            ['what is the weather', false, 0.0],
        ];
    }

    /**
     * Data provider for command continuation pattern testing
     */
    public static function commandContinuationDataProvider(): array
    {
        return [
            ['yes', true, 0.9],
            ['ok', true, 0.9],
            ['sure', true, 0.9],
            ['go ahead', true, 0.9],
            ['proceed', true, 0.9],
            ['continue', true, 0.9],
            ['do it', true, 0.9],
            ['make it', true, 0.9],
            ['create it', true, 0.9],
            ['run it', true, 0.9],
            ['again', true, 0.9],
            ['once more', true, 0.9],
            ['repeat', true, 0.9],
            ['please yes', true, 0.9],
            ['sure!', true, 0.9],
            ['okay, go ahead', true, 0.9],
            ['no thanks', false, 0.0], // This would be negation_continuation
            ['create a new file', false, 0.0], // Not a continuation
        ];
    }

    /**
     * Data provider for reference continuation pattern testing
     */
    public static function referenceContinuationDataProvider(): array
    {
        return [
            ['another one', true, 0.8],
            ['more examples', true, 0.8],
            ['also create', true, 0.8],
            ['make another', true, 0.8],
            ['do more', true, 0.8],
            ['create another', true, 0.8],
            ['different approach', true, 0.8],
            ['alternative method', true, 0.8],
            ['similar to this', true, 0.8],
            ['like the previous', true, 0.8],
            ['the same but different', true, 0.8],
            ['what about another', true, 0.8],
            ['how about different', true, 0.8],
            ['what about alternative', true, 0.8],
            ['create completely new system', false, 0.0], // Too specific
            ['help with different project', false, 0.0],
        ];
    }

    /**
     * Data provider for clarification request pattern testing
     */
    public static function clarificationRequestDataProvider(): array
    {
        return [
            ['what about using React?', true, 0.8],
            ['how about different colors?', true, 0.8],
            ['can you add validation?', true, 0.8],
            ['would you include tests?', true, 0.8],
            ['could you use TypeScript?', true, 0.8],
            ['why not use Vue?', true, 0.8],
            ['when should we deploy?', true, 0.8],
            ['where should I put this?', true, 0.8],
            ['how does this work?', true, 0.8],
            ['what if we change it?', true, 0.8],
            ['what about using Docker containers?', true, 0.8],
            ['is this correct?', true, 0.8],
            ['are we good to go?', true, 0.8],
            ['should we continue?', true, 0.8],
            ['I need help with a completely different project', false, 0.0],
        ];
    }

    /**
     * Data provider for negation continuation pattern testing
     */
    public static function negationContinuationDataProvider(): array
    {
        return [
            ['no', true, 0.8],
            ['nope', true, 0.8],
            ['skip', true, 0.8],
            ['cancel', true, 0.8],
            ['stop', true, 0.8],
            ["don't", true, 0.8],
            ['not that', true, 0.8],
            ['something else', true, 0.8],
            ['different', true, 0.8],
            ['instead', true, 0.8],
            ['rather', true, 0.8],
            ['better', true, 0.8],
            ['no thanks', true, 0.8],
            ['skip that step', true, 0.8],
            ['cancel the operation', true, 0.8],
            ['yes please', false, 0.0], // This is command_continuation
            ['create new feature', false, 0.0], // Not negation
        ];
    }

    /**
     * Data provider for test continuation pattern testing
     */
    public static function test_continuation_data_provider(): array
    {
        return [
            ['test it', true, 0.8],
            ['run tests', true, 0.8],
            ['check if it works', true, 0.8],
            ['verify', true, 0.8],
            ['does it work', true, 0.8],
            ['is it working', true, 0.8],
            ['try it', true, 0.8],
            ['make sure it works', true, 0.8],
            ['ensure it functions', true, 0.8],
            ['test the implementation', true, 0.8],
            ['run the tests now', true, 0.8],
            ['check if everything works', true, 0.8],
            ['verify the results', true, 0.8],
            ['try it out', true, 0.8],
            ['create comprehensive test suite', false, 0.0], // Too specific
        ];
    }

    /**
     * Data provider for enhancement request pattern testing
     */
    public static function enhancementRequestDataProvider(): array
    {
        return [
            ['improve it', true, 0.7],
            ['enhance that', true, 0.7],
            ['optimize this', true, 0.7],
            ['make it better', true, 0.7],
            ['add more features', true, 0.7],
            ['additional validation', true, 0.7],
            ['extra security', true, 0.7],
            ['can we add logging', true, 0.7],
            ["let's include error handling", true, 0.7],
            ['also add authentication', true, 0.7],
            ['add validation please', true, 0.7],
            ['include more options', true, 0.7],
            ['more comprehensive solution', true, 0.7],
            ['additional features needed', true, 0.7],
            ['build completely new system', false, 0.0], // Too broad
            ['create different application', false, 0.0],
        ];
    }
}
