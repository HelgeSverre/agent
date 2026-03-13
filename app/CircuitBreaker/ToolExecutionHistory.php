<?php

namespace App\CircuitBreaker;

/**
 * Manages execution history and detects patterns in tool calls
 */
class ToolExecutionHistory
{
    /** @var ToolExecutionRecord[] */
    private array $history = [];

    private ParameterSimilarityDetector $similarityDetector;

    private int $lastCleanup = 0;

    public function __construct(
        private readonly int $maxHistorySize = 1000,
        private readonly int $timeWindow = 300, // 5 minutes
        private readonly float $similarityThreshold = 0.85,
        ?ParameterSimilarityDetector $similarityDetector = null
    ) {
        $this->similarityDetector = $similarityDetector ?? new ParameterSimilarityDetector;
        $this->lastCleanup = time();
    }

    /**
     * Add execution record to history
     */
    public function addExecution(ToolExecutionRecord $record): void
    {
        $this->history[] = $record;

        // Periodic cleanup
        if (count($this->history) % 100 === 0 || time() - $this->lastCleanup > 60) {
            $this->cleanup();
        }
    }

    /**
     * Check if tool execution should be blocked due to recent duplicates
     */
    public function shouldBlockExecution(
        string $toolName,
        array $parameters,
        int $duplicateThreshold = 2
    ): bool {
        $recentExecutions = $this->getRecentExecutions($toolName, $this->timeWindow);

        if (count($recentExecutions) < $duplicateThreshold) {
            return false;
        }

        $similarCount = 0;

        foreach ($recentExecutions as $record) {
            if ($this->similarityDetector->areSimilar(
                $parameters,
                $record->parameters,
                $this->similarityThreshold
            )) {
                $similarCount++;
                if ($similarCount >= $duplicateThreshold) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get execution pattern analysis for a tool
     */
    public function analyzeExecutionPattern(string $toolName, int $lookbackSeconds = 300): array
    {
        $recentExecutions = $this->getRecentExecutions($toolName, $lookbackSeconds);

        if (empty($recentExecutions)) {
            return [
                'total_executions' => 0,
                'unique_parameter_sets' => 0,
                'average_similarity' => 0.0,
                'potential_loop' => false,
                'pattern_type' => 'none',
            ];
        }

        $uniqueParameterSets = [];
        $similarities = [];

        foreach ($recentExecutions as $i => $record) {
            $found = false;
            foreach ($uniqueParameterSets as $j => $uniqueParams) {
                $similarity = $this->similarityDetector->calculateSimilarity(
                    $record->parameters,
                    $uniqueParams
                );

                $similarities[] = $similarity;

                if ($similarity >= $this->similarityThreshold) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $uniqueParameterSets[] = $record->parameters;
            }
        }

        $avgSimilarity = empty($similarities) ? 0.0 : array_sum($similarities) / count($similarities);
        $totalExecutions = count($recentExecutions);
        $uniqueCount = count($uniqueParameterSets);

        // Detect potential loops
        $potentialLoop = false;
        $patternType = 'varied';

        if ($totalExecutions >= 3) {
            $similarityRatio = $uniqueCount / $totalExecutions;

            if ($similarityRatio < 0.3 && $avgSimilarity > 0.8) {
                $potentialLoop = true;
                $patternType = 'repetitive';
            } elseif ($similarityRatio < 0.5 && $avgSimilarity > 0.6) {
                $potentialLoop = true;
                $patternType = 'cyclical';
            } elseif ($this->detectProgressivePattern($recentExecutions)) {
                $potentialLoop = true;
                $patternType = 'progressive';
            }
        }

        return [
            'total_executions' => $totalExecutions,
            'unique_parameter_sets' => $uniqueCount,
            'average_similarity' => $avgSimilarity,
            'potential_loop' => $potentialLoop,
            'pattern_type' => $patternType,
            'time_span' => $totalExecutions > 1 ?
                ($recentExecutions[count($recentExecutions) - 1]->timestamp - $recentExecutions[0]->timestamp) : 0,
            'execution_frequency' => $totalExecutions > 1 ?
                $totalExecutions / max(1, ($recentExecutions[count($recentExecutions) - 1]->timestamp - $recentExecutions[0]->timestamp)) : 0,
        ];
    }

    /**
     * Get recent executions for a specific tool
     */
    public function getRecentExecutions(string $toolName, int $lookbackSeconds): array
    {
        $cutoffTime = time() - $lookbackSeconds;

        return array_filter(
            $this->history,
            fn (ToolExecutionRecord $record) => $record->toolName === $toolName && $record->timestamp >= $cutoffTime
        );
    }

    /**
     * Get most similar execution to current parameters
     */
    public function findMostSimilarExecution(string $toolName, array $parameters): ?ToolExecutionRecord
    {
        $recentExecutions = $this->getRecentExecutions($toolName, $this->timeWindow);
        $mostSimilar = null;
        $highestSimilarity = 0.0;

        foreach ($recentExecutions as $record) {
            $similarity = $this->similarityDetector->calculateSimilarity($parameters, $record->parameters);
            if ($similarity > $highestSimilarity) {
                $highestSimilarity = $similarity;
                $mostSimilar = $record;
            }
        }

        return $highestSimilarity >= $this->similarityThreshold ? $mostSimilar : null;
    }

    /**
     * Detect if executions show progressive pattern (e.g., incrementing values)
     */
    private function detectProgressivePattern(array $executions): bool
    {
        if (count($executions) < 3) {
            return false;
        }

        // Sort by timestamp
        usort($executions, fn ($a, $b) => $a->timestamp - $b->timestamp);

        // Look for numeric progression in main parameters
        $values = [];
        foreach ($executions as $record) {
            $mainParam = $record->getMainParameter();
            if (is_numeric($mainParam)) {
                $values[] = floatval($mainParam);
            }
        }

        if (count($values) < 3) {
            return false;
        }

        // Check if values show consistent progression
        $differences = [];
        for ($i = 1; $i < count($values); $i++) {
            $differences[] = $values[$i] - $values[$i - 1];
        }

        $avgDiff = array_sum($differences) / count($differences);
        $variance = 0.0;

        foreach ($differences as $diff) {
            $variance += pow($diff - $avgDiff, 2);
        }

        $variance /= count($differences);
        $stdDev = sqrt($variance);

        // Consider it progressive if standard deviation is less than 10% of average
        return abs($avgDiff) > 0 && ($stdDev / abs($avgDiff)) < 0.1;
    }

    /**
     * Clean up old history entries
     */
    private function cleanup(): void
    {
        $cutoffTime = time() - $this->timeWindow * 2; // Keep double the time window

        // Remove old entries
        $this->history = array_filter(
            $this->history,
            fn (ToolExecutionRecord $record) => $record->timestamp >= $cutoffTime
        );

        // Limit size
        if (count($this->history) > $this->maxHistorySize) {
            $this->history = array_slice($this->history, -$this->maxHistorySize);
        }

        // Reindex array
        $this->history = array_values($this->history);

        $this->lastCleanup = time();
    }

    /**
     * Get history statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_records' => count($this->history),
            'tools' => array_count_values(array_map(fn ($r) => $r->toolName, $this->history)),
            'success_rate' => empty($this->history) ? 0.0 :
                count(array_filter($this->history, fn ($r) => $r->success)) / count($this->history),
            'oldest_record_age' => empty($this->history) ? 0 :
                time() - min(array_map(fn ($r) => $r->timestamp, $this->history)),
            'memory_usage' => strlen(serialize($this->history)),
        ];
    }

    /**
     * Clear all history
     */
    public function clear(): void
    {
        $this->history = [];
        $this->lastCleanup = time();
    }
}
