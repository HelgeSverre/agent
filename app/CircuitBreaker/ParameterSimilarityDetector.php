<?php

namespace App\CircuitBreaker;

/**
 * Detects similarity between tool parameters to identify potential loops
 */
class ParameterSimilarityDetector
{
    public function __construct(
        private readonly float $stringThreshold = 0.8,
        private readonly float $arrayThreshold = 0.9,
        private readonly bool $enableFuzzyMatching = true
    ) {}

    /**
     * Calculate similarity between two parameter sets
     * Returns a score between 0.0 (completely different) and 1.0 (identical)
     */
    public function calculateSimilarity(array $params1, array $params2): float
    {
        // Exact match
        if ($params1 === $params2) {
            return 1.0;
        }

        // If parameter counts differ significantly, they're likely different
        $keyDiff = array_diff_key($params1, $params2) + array_diff_key($params2, $params1);
        if (count($keyDiff) > min(count($params1), count($params2)) * 0.5) {
            return 0.0;
        }

        $totalScore = 0.0;
        $comparisons = 0;

        // Compare each parameter
        foreach ($params1 as $key => $value1) {
            if (! isset($params2[$key])) {
                continue;
            }

            $value2 = $params2[$key];
            $score = $this->compareValues($value1, $value2);
            $totalScore += $score;
            $comparisons++;
        }

        return $comparisons > 0 ? $totalScore / $comparisons : 0.0;
    }

    /**
     * Check if parameters are similar enough to be considered duplicates
     */
    public function areSimilar(array $params1, array $params2, ?float $threshold = null): bool
    {
        $threshold = $threshold ?? $this->arrayThreshold;

        return $this->calculateSimilarity($params1, $params2) >= $threshold;
    }

    /**
     * Compare two values and return similarity score
     */
    private function compareValues(mixed $value1, mixed $value2): float
    {
        // Exact match
        if ($value1 === $value2) {
            return 1.0;
        }

        // Type mismatch
        if (gettype($value1) !== gettype($value2)) {
            return 0.0;
        }

        return match (gettype($value1)) {
            'string' => $this->compareStrings($value1, $value2),
            'array' => $this->compareArrays($value1, $value2),
            'integer', 'double' => $this->compareNumbers($value1, $value2),
            'boolean' => $value1 === $value2 ? 1.0 : 0.0,
            'object' => $this->compareObjects($value1, $value2),
            default => 0.0
        };
    }

    /**
     * Compare string similarity using Levenshtein distance
     */
    private function compareStrings(string $str1, string $str2): float
    {
        if ($str1 === $str2) {
            return 1.0;
        }

        if (! $this->enableFuzzyMatching) {
            return 0.0;
        }

        $maxLength = max(strlen($str1), strlen($str2));
        if ($maxLength === 0) {
            return 1.0;
        }

        $levenshtein = levenshtein($str1, $str2);
        $similarity = 1.0 - ($levenshtein / $maxLength);

        return $similarity >= $this->stringThreshold ? $similarity : 0.0;
    }

    /**
     * Compare arrays using Jaccard similarity
     */
    private function compareArrays(array $arr1, array $arr2): float
    {
        if ($arr1 === $arr2) {
            return 1.0;
        }

        // For associative arrays, compare as key-value pairs
        if ($this->isAssociativeArray($arr1) || $this->isAssociativeArray($arr2)) {
            return $this->compareAssociativeArrays($arr1, $arr2);
        }

        // For indexed arrays, use Jaccard similarity
        $intersection = array_intersect($arr1, $arr2);
        $union = array_unique(array_merge($arr1, $arr2));

        if (empty($union)) {
            return 1.0;
        }

        return count($intersection) / count($union);
    }

    /**
     * Compare associative arrays recursively
     */
    private function compareAssociativeArrays(array $arr1, array $arr2): float
    {
        $allKeys = array_unique(array_merge(array_keys($arr1), array_keys($arr2)));
        if (empty($allKeys)) {
            return 1.0;
        }

        $totalScore = 0.0;
        $keyCount = 0;

        foreach ($allKeys as $key) {
            if (isset($arr1[$key]) && isset($arr2[$key])) {
                $totalScore += $this->compareValues($arr1[$key], $arr2[$key]);
                $keyCount++;
            } elseif (isset($arr1[$key]) || isset($arr2[$key])) {
                // Key exists in only one array
                $keyCount++;
            }
        }

        return $keyCount > 0 ? $totalScore / $keyCount : 0.0;
    }

    /**
     * Compare numeric values with tolerance
     */
    private function compareNumbers(int|float $num1, int|float $num2): float
    {
        if ($num1 == $num2) {
            return 1.0;
        }

        // For very small numbers, use absolute difference
        $maxAbs = max(abs($num1), abs($num2));
        if ($maxAbs < 1e-10) {
            return abs($num1 - $num2) < 1e-10 ? 1.0 : 0.0;
        }

        // Use relative difference for larger numbers
        $relativeDiff = abs($num1 - $num2) / $maxAbs;

        return max(0.0, 1.0 - $relativeDiff);
    }

    /**
     * Compare objects by converting to arrays
     */
    private function compareObjects(object $obj1, object $obj2): float
    {
        // Convert objects to arrays for comparison
        $arr1 = json_decode(json_encode($obj1), true);
        $arr2 = json_decode(json_encode($obj2), true);

        return $this->compareArrays($arr1, $arr2);
    }

    /**
     * Check if array is associative
     */
    private function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
