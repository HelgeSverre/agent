<?php

namespace Tests\Unit\CircuitBreaker;

use App\CircuitBreaker\ParameterSimilarityDetector;
use PHPUnit\Framework\TestCase;

class ParameterSimilarityDetectorTest extends TestCase
{
    private ParameterSimilarityDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ParameterSimilarityDetector(
            stringThreshold: 0.8,
            arrayThreshold: 0.9,
            enableFuzzyMatching: true
        );
    }

    public function test_exact_match_returns_one()
    {
        $params1 = ['file_path' => '/tmp/test.txt'];
        $params2 = ['file_path' => '/tmp/test.txt'];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertEquals(1.0, $similarity);
    }

    public function test_completely_different_parameters_return_zero()
    {
        $params1 = ['file_path' => '/tmp/test.txt'];
        $params2 = ['command' => 'ls -la'];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertEquals(0.0, $similarity);
    }

    public function test_similar_strings_have_high_similarity()
    {
        $params1 = ['file_path' => '/tmp/test1.txt'];
        $params2 = ['file_path' => '/tmp/test2.txt'];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertGreaterThan(0.8, $similarity);
    }

    public function test_arrays_jaccard_similarity()
    {
        $params1 = ['tags' => ['php', 'testing', 'unit']];
        $params2 = ['tags' => ['php', 'testing', 'integration']];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        // 2 common elements out of 4 total = 0.5
        $this->assertEqualsWithDelta(0.5, $similarity, 0.1);
    }

    public function test_associative_arrays_compared_recursively()
    {
        $params1 = [
            'config' => ['debug' => true, 'timeout' => 30],
            'options' => ['verbose' => false],
        ];
        $params2 = [
            'config' => ['debug' => true, 'timeout' => 25],
            'options' => ['verbose' => false],
        ];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertGreaterThan(0.7, $similarity);
    }

    public function test_are_similar_with_threshold()
    {
        $params1 = ['query' => 'php frameworks'];
        $params2 = ['query' => 'php framework']; // Missing 's'

        $this->assertTrue($this->detector->areSimilar($params1, $params2, 0.8));
        $this->assertFalse($this->detector->areSimilar($params1, $params2, 0.95));
    }

    public function test_numeric_values_similarity()
    {
        $params1 = ['timeout' => 30];
        $params2 = ['timeout' => 31];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertGreaterThan(0.9, $similarity);
    }

    public function test_boolean_values_exact_match_only()
    {
        $params1 = ['enabled' => true];
        $params2 = ['enabled' => true];
        $params3 = ['enabled' => false];

        $this->assertEquals(1.0, $this->detector->calculateSimilarity($params1, $params2));
        $this->assertEquals(0.0, $this->detector->calculateSimilarity($params1, $params3));
    }

    public function test_mixed_parameter_types()
    {
        $params1 = [
            'file_path' => '/tmp/test.txt',
            'timeout' => 30,
            'enabled' => true,
            'tags' => ['php', 'test'],
        ];
        $params2 = [
            'file_path' => '/tmp/test.log',
            'timeout' => 31,
            'enabled' => true,
            'tags' => ['php', 'testing'],
        ];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertGreaterThan(0.6, $similarity);
        $this->assertLessThan(1.0, $similarity);
    }

    public function test_fuzzy_matching_disabled()
    {
        $detector = new ParameterSimilarityDetector(
            stringThreshold: 0.8,
            arrayThreshold: 0.9,
            enableFuzzyMatching: false
        );

        $params1 = ['query' => 'test'];
        $params2 = ['query' => 'testing'];

        $similarity = $detector->calculateSimilarity($params1, $params2);

        $this->assertEquals(0.0, $similarity);
    }

    public function test_empty_parameters()
    {
        $params1 = [];
        $params2 = [];

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertEquals(1.0, $similarity);
    }

    public function test_parameter_count_difference()
    {
        $params1 = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        $params2 = ['x' => 1]; // Completely different keys and much smaller

        $similarity = $this->detector->calculateSimilarity($params1, $params2);

        $this->assertEquals(0.0, $similarity);
    }
}
