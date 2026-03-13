<?php

namespace App\Agent\Context\Services;

use App\Agent\Context\ContextCompressor;

class CompressionTriggerService
{
    protected array $config;

    protected ContextCompressor $compressor;

    protected array $sessionMetrics = [];

    public function __construct()
    {
        $this->config = config('app.context_compression', []);
        $this->compressor = new ContextCompressor;
    }

    /**
     * Determine if compression should be triggered
     */
    public function shouldCompress(array $steps, ?string $sessionId = null): array
    {
        $triggers = $this->config['triggers'] ?? [];
        $result = [
            'should_compress' => false,
            'trigger_reasons' => [],
            'compression_strategy' => 'none',
            'priority' => 'low',
        ];

        // Skip if compression is disabled
        if (! ($this->config['enabled'] ?? true)) {
            return $result;
        }

        $stepCount = count($steps);
        $tokenEstimate = $this->estimateTokenCount($steps);
        $memoryUsage = $this->estimateMemoryUsage($steps);

        // Update session metrics
        if ($sessionId) {
            $this->updateSessionMetrics($sessionId, $stepCount, $tokenEstimate);
        }

        // Check size-based triggers
        if ($this->checkSizeTriggers($stepCount, $tokenEstimate, $triggers, $result)) {
            $result['should_compress'] = true;
        }

        // Check time-based triggers
        if ($this->checkTimeTriggers($steps, $triggers, $result)) {
            $result['should_compress'] = true;
        }

        // Check memory-based triggers
        if ($this->checkMemoryTriggers($memoryUsage, $triggers, $result)) {
            $result['should_compress'] = true;
        }

        // Check pattern-based triggers
        if ($this->checkPatternTriggers($steps, $result)) {
            $result['should_compress'] = true;
        }

        // Check session boundary triggers
        if ($sessionId && $this->checkSessionBoundaryTriggers($sessionId, $steps, $result)) {
            $result['should_compress'] = true;
        }

        // Determine compression strategy and priority
        if ($result['should_compress']) {
            $this->determineCompressionStrategy($result, $stepCount, $tokenEstimate);
        }

        return $result;
    }

    /**
     * Get optimal compression segments from steps
     */
    public function getCompressionSegments(array $steps, array $triggerResult): array
    {
        if (! $triggerResult['should_compress']) {
            return [];
        }

        $strategy = $triggerResult['compression_strategy'];

        $segments = match ($strategy) {
            'aggressive' => $this->getAggressiveSegments($steps),
            'selective' => $this->getSelectiveSegments($steps),
            'boundary' => $this->getBoundarySegments($steps),
            default => $this->getDefaultSegments($steps),
        };

        return $segments;
    }

    /**
     * Check if immediate compression is required (emergency mode)
     */
    public function isEmergencyCompression(array $steps, ?float $memoryUsage = null): bool
    {
        $emergencyThresholds = $this->config['emergency_triggers'] ?? [];

        // Emergency token threshold
        $tokenCount = $this->estimateTokenCount($steps);
        $emergencyTokenThreshold = $emergencyThresholds['token_threshold'] ?? 15000;
        if ($tokenCount > $emergencyTokenThreshold) {
            return true;
        }

        // Emergency step threshold
        $emergencyStepThreshold = $emergencyThresholds['step_threshold'] ?? 50;
        if (count($steps) > $emergencyStepThreshold) {
            return true;
        }

        // Emergency memory threshold
        if ($memoryUsage !== null) {
            $emergencyMemoryThreshold = $emergencyThresholds['memory_threshold'] ?? 0.95;
            if ($memoryUsage > $emergencyMemoryThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get compression recommendations
     *
     * @todo     inconsistent argument sorting
     */
    public function getCompressionRecommendations(array $steps, ?string $sessionId = null): array
    {
        $analysis = $this->analyzeContext($steps);
        $recommendations = [];

        if ($analysis['critical_information_ratio'] > 0.8) {
            $recommendations[] = [
                'type' => 'strategy',
                'message' => 'High critical information ratio detected - use selective compression',
                'priority' => 'high',
            ];
        }

        if ($analysis['repetitive_patterns'] > 3) {
            $recommendations[] = [
                'type' => 'optimization',
                'message' => 'Repetitive patterns detected - pattern-based compression recommended',
                'priority' => 'medium',
            ];
        }

        if ($analysis['file_operations'] > 10) {
            $recommendations[] = [
                'type' => 'preservation',
                'message' => 'Many file operations detected - ensure file history is preserved',
                'priority' => 'high',
            ];
        }

        $tokenCount = $this->estimateTokenCount($steps);
        if ($tokenCount > 12000) {
            $recommendations[] = [
                'type' => 'performance',
                'message' => 'Large context size - consider aggressive compression',
                'priority' => 'medium',
            ];
        }

        return $recommendations;
    }

    /**
     * Check size-based triggers
     */
    protected function checkSizeTriggers(int $stepCount, int $tokenEstimate, array $triggers, array &$result): bool
    {
        $triggered = false;

        $stepThreshold = $triggers['step_threshold'] ?? 25;
        if ($stepCount >= $stepThreshold) {
            $result['trigger_reasons'][] = "Step count ({$stepCount}) exceeds threshold ({$stepThreshold})";
            $triggered = true;
        }

        $tokenThreshold = $triggers['token_threshold'] ?? 8000;
        if ($tokenEstimate >= $tokenThreshold) {
            $result['trigger_reasons'][] = "Token estimate ({$tokenEstimate}) exceeds threshold ({$tokenThreshold})";
            $triggered = true;
        }

        return $triggered;
    }

    /**
     * Check time-based triggers
     */
    protected function checkTimeTriggers(array $steps, array $triggers, array &$result): bool
    {
        $timeThreshold = $triggers['time_threshold'] ?? 3600; // 1 hour

        if (empty($steps)) {
            return false;
        }

        // Get timestamp from first and last steps
        $firstTimestamp = $this->extractTimestamp($steps[0]) ?? time();
        $lastTimestamp = $this->extractTimestamp($steps[count($steps) - 1]) ?? time();

        $duration = $lastTimestamp - $firstTimestamp;

        if ($duration >= $timeThreshold) {
            $result['trigger_reasons'][] = "Context duration ({$duration}s) exceeds threshold ({$timeThreshold}s)";

            return true;
        }

        return false;
    }

    /**
     * Check memory-based triggers
     */
    protected function checkMemoryTriggers(float $memoryUsage, array $triggers, array &$result): bool
    {
        $memoryThreshold = $triggers['memory_threshold'] ?? 0.8;

        if ($memoryUsage >= $memoryThreshold) {
            $result['trigger_reasons'][] = "Memory usage ({$memoryUsage}) exceeds threshold ({$memoryThreshold})";
            $result['priority'] = 'high';

            return true;
        }

        return false;
    }

    /**
     * Check pattern-based triggers
     */
    protected function checkPatternTriggers(array $steps, array &$result): bool
    {
        $patterns = $this->detectRepetitivePatterns($steps);
        $patternThreshold = $this->config['pattern_threshold'] ?? 3;

        foreach ($patterns as $pattern => $count) {
            if ($count >= $patternThreshold) {
                $result['trigger_reasons'][] = "Repetitive pattern detected: {$pattern} (occurs {$count} times)";

                return true;
            }
        }

        return false;
    }

    /**
     * Check session boundary triggers
     */
    protected function checkSessionBoundaryTriggers(string $sessionId, array $steps, array &$result): bool
    {
        // Check if this looks like a new task boundary
        $lastSteps = array_slice($steps, -5);
        $finalAnswerCount = 0;
        $newTaskIndicators = 0;

        foreach ($lastSteps as $step) {
            if ($step['type'] === 'action') {
                $action = is_array($step['content']) ? ($step['content']['action'] ?? '') : '';
                if ($action === 'final_answer') {
                    $finalAnswerCount++;
                }
            }

            if ($step['type'] === 'thought' && is_string($step['content'])) {
                if (preg_match('/new task|next task|different task/i', $step['content'])) {
                    $newTaskIndicators++;
                }
            }
        }

        if ($finalAnswerCount > 0 && count($steps) > 10) {
            $result['trigger_reasons'][] = 'Task completion detected - good compression boundary';

            return true;
        }

        if ($newTaskIndicators > 0) {
            $result['trigger_reasons'][] = 'New task indicators detected';

            return true;
        }

        return false;
    }

    /**
     * Determine compression strategy based on analysis
     */
    protected function determineCompressionStrategy(array &$result, int $stepCount, int $tokenEstimate): void
    {
        $triggerReasons = $result['trigger_reasons'];
        $joined = implode(' ', $triggerReasons);

        // Emergency compression for very large contexts
        if ($stepCount > 50 || $tokenEstimate > 15000) {
            $result['compression_strategy'] = 'aggressive';
            $result['priority'] = 'critical';

            return;
        }

        // Memory pressure requires immediate action
        if (str_contains($joined, 'memory')) {
            $result['compression_strategy'] = 'aggressive';
            $result['priority'] = 'high';

            return;
        }

        // Pattern-based compression for repetitive content
        if (str_contains($joined, 'pattern')) {
            $result['compression_strategy'] = 'selective';
            $result['priority'] = 'medium';

            return;
        }

        // Boundary-based compression at natural breaks
        if (str_contains($joined, 'Task completion') ||
            str_contains($joined, 'New task')) {
            $result['compression_strategy'] = 'boundary';
            $result['priority'] = 'low';

            return;
        }

        // Default strategy for size-based triggers
        $result['compression_strategy'] = 'selective';
        $result['priority'] = 'medium';
    }

    /**
     * Estimate token count for context
     */
    protected function estimateTokenCount(array $steps): int
    {
        $totalChars = 0;

        foreach ($steps as $step) {
            $content = $step['content'] ?? '';
            if (is_array($content)) {
                $totalChars += strlen(serialize($content));
            } else {
                $totalChars += strlen($content);
            }
        }

        // Rough estimate: 4 characters per token
        return intval($totalChars / 4);
    }

    /**
     * Estimate memory usage
     */
    protected function estimateMemoryUsage(array $steps): float
    {
        // This is a simplified estimate
        // In a real implementation, you might check actual memory usage
        $estimated = strlen(serialize($steps));
        $memoryLimit = 128 * 1024 * 1024; // 128MB estimate

        return $estimated / $memoryLimit;
    }

    /**
     * Extract timestamp from step
     */
    protected function extractTimestamp(array $step): ?int
    {
        // Try to extract timestamp from step metadata
        if (isset($step['timestamp'])) {
            return $step['timestamp'];
        }

        if (isset($step['metadata']['timestamp'])) {
            return $step['metadata']['timestamp'];
        }

        return null;
    }

    /**
     * Detect repetitive patterns in steps
     */
    protected function detectRepetitivePatterns(array $steps): array
    {
        $patterns = [];
        $actionSequences = [];

        // Extract action sequences
        foreach ($steps as $step) {
            if ($step['type'] === 'action') {
                $action = is_array($step['content']) ? ($step['content']['action'] ?? '') : '';
                $actionSequences[] = $action;
            }
        }

        // Look for repeating sequences of 2-3 actions
        for ($length = 2; $length <= 3; $length++) {
            for ($i = 0; $i <= count($actionSequences) - $length; $i++) {
                $sequence = array_slice($actionSequences, $i, $length);
                $patternKey = implode('->', $sequence);

                if (! isset($patterns[$patternKey])) {
                    $patterns[$patternKey] = 0;
                }

                // Count occurrences of this pattern
                for ($j = $i + $length; $j <= count($actionSequences) - $length; $j++) {
                    $compare = array_slice($actionSequences, $j, $length);
                    if ($sequence === $compare) {
                        $patterns[$patternKey]++;
                        break;
                    }
                }
            }
        }

        return array_filter($patterns, fn ($count) => $count > 0);
    }

    /**
     * Analyze context for compression planning
     */
    protected function analyzeContext(array $steps): array
    {
        $analysis = [
            'total_steps' => count($steps),
            'critical_information_ratio' => 0,
            'repetitive_patterns' => 0,
            'file_operations' => 0,
            'user_interactions' => 0,
            'error_count' => 0,
        ];

        $criticalCount = 0;

        foreach ($steps as $step) {
            $content = $step['content'] ?? '';

            // Count critical information
            if ($this->isCriticalStep($step)) {
                $criticalCount++;
            }

            // Count file operations
            if ($step['type'] === 'action' && is_array($content)) {
                $action = $content['action'] ?? '';
                if (in_array($action, ['write_file', 'read_file', 'run_command'])) {
                    $analysis['file_operations']++;
                }
            }

            // Count errors
            if (is_string($content) && str_contains($content, 'Error:')) {
                $analysis['error_count']++;
            }
        }

        $analysis['critical_information_ratio'] = $analysis['total_steps'] > 0
            ? $criticalCount / $analysis['total_steps']
            : 0;

        $patterns = $this->detectRepetitivePatterns($steps);
        $analysis['repetitive_patterns'] = count($patterns);

        return $analysis;
    }

    /**
     * Check if step contains critical information
     */
    protected function isCriticalStep(array $step): bool
    {
        $content = $step['content'] ?? '';
        $type = $step['type'] ?? '';

        // File operations are critical
        if ($type === 'action' && is_array($content)) {
            $action = $content['action'] ?? '';
            if (in_array($action, ['write_file', 'run_command', 'final_answer'])) {
                return true;
            }
        }

        // Important observations
        if ($type === 'observation' && is_string($content)) {
            if (str_contains($content, 'File written') ||
                str_contains($content, 'created') ||
                str_contains($content, 'Error:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get aggressive compression segments
     */
    protected function getAggressiveSegments(array $steps): array
    {
        // Compress everything except the most recent critical steps
        $segments = [];
        $criticalSteps = [];

        // Find critical steps
        foreach ($steps as $index => $step) {
            if ($this->isCriticalStep($step)) {
                $criticalSteps[] = $index;
            }
        }

        // Keep last 5 critical steps and recent 10 steps
        $keepIndices = array_merge(
            array_slice($criticalSteps, -5),
            range(max(0, count($steps) - 10), count($steps) - 1)
        );
        $keepIndices = array_unique($keepIndices);

        $compressIndices = array_diff(range(0, count($steps) - 1), $keepIndices);

        if (! empty($compressIndices)) {
            $segments[] = [
                'start' => min($compressIndices),
                'end' => max($compressIndices),
                'type' => 'aggressive',
            ];
        }

        return $segments;
    }

    /**
     * Get selective compression segments
     */
    protected function getSelectiveSegments(array $steps): array
    {
        // Compress non-critical segments
        $segments = [];
        $currentSegment = null;

        foreach ($steps as $index => $step) {
            $isCritical = $this->isCriticalStep($step);

            if (! $isCritical) {
                if ($currentSegment === null) {
                    $currentSegment = ['start' => $index, 'end' => $index, 'type' => 'selective'];
                } else {
                    $currentSegment['end'] = $index;
                }
            } else {
                if ($currentSegment !== null && ($currentSegment['end'] - $currentSegment['start']) >= 2) {
                    $segments[] = $currentSegment;
                }
                $currentSegment = null;
            }
        }

        // Add final segment if exists
        if ($currentSegment !== null && ($currentSegment['end'] - $currentSegment['start']) >= 2) {
            $segments[] = $currentSegment;
        }

        return $segments;
    }

    /**
     * Get boundary-based compression segments
     */
    protected function getBoundarySegments(array $steps): array
    {
        // Find natural boundaries (task completions)
        $segments = [];
        $lastBoundary = 0;

        foreach ($steps as $index => $step) {
            if ($step['type'] === 'action' && is_array($step['content'])) {
                $action = $step['content']['action'] ?? '';
                if ($action === 'final_answer' && $index - $lastBoundary > 5) {
                    $segments[] = [
                        'start' => $lastBoundary,
                        'end' => $index - 1,
                        'type' => 'boundary',
                    ];
                    $lastBoundary = $index;
                }
            }
        }

        return $segments;
    }

    /**
     * Get default compression segments
     */
    protected function getDefaultSegments(array $steps): array
    {
        // Simple strategy: compress older half, keep newer half
        $totalSteps = count($steps);
        $splitPoint = intval($totalSteps * 0.6);

        if ($splitPoint > 5) {
            return [[
                'start' => 0,
                'end' => $splitPoint - 1,
                'type' => 'default',
            ]];
        }

        return [];
    }

    /**
     * Update session metrics for trend analysis
     */
    protected function updateSessionMetrics(string $sessionId, int $stepCount, int $tokenEstimate): void
    {
        if (! isset($this->sessionMetrics[$sessionId])) {
            $this->sessionMetrics[$sessionId] = [
                'step_count' => 0,
                'token_estimate' => 0,
                'compression_count' => 0,
                'last_updated' => time(),
            ];
        }

        $this->sessionMetrics[$sessionId]['step_count'] = $stepCount;
        $this->sessionMetrics[$sessionId]['token_estimate'] = $tokenEstimate;
        $this->sessionMetrics[$sessionId]['last_updated'] = time();
    }
}
