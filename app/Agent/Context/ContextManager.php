<?php

namespace App\Agent\Context;

use App\Agent\Context\Services\CompressionTriggerService;
use App\Agent\Context\Services\MemoryManager;

class ContextManager
{
    protected int $maxSteps = 15;

    protected array $persistentContext = [];

    protected array $contextScores = [];

    protected ?ContextCompressor $compressor = null;

    protected ?CompressionTriggerService $triggerService = null;

    protected ?MemoryManager $memoryManager = null;

    protected array $config;

    public function __construct()
    {
        $this->config = config('app.context_compression', []);
        $this->compressor = new ContextCompressor;
        $this->triggerService = new CompressionTriggerService;
        $this->memoryManager = new MemoryManager;

        // Update max steps from config
        $this->maxSteps = $this->config['triggers']['step_threshold'] ?? 25;
    }

    /**
     * Manage context window with intelligent compression and trimming
     */
    public function manageContext(array $steps, ?string $sessionId = null): array
    {
        if (! ($this->config['enabled'] ?? true)) {
            return $this->fallbackManagement($steps);
        }

        // Check if compression should be triggered
        $triggerResult = $this->triggerService->shouldCompress($steps, $sessionId);

        if ($triggerResult['should_compress']) {
            $steps = $this->performIntelligentCompression($steps, $triggerResult, $sessionId);
        }

        // If still over limit after compression, trim intelligently
        if (count($steps) > $this->maxSteps) {
            $steps = $this->intelligentTrim($steps);
        }

        return $steps;
    }

    /**
     * Perform intelligent compression based on trigger analysis
     */
    protected function performIntelligentCompression(array $steps, array $triggerResult, ?string $sessionId): array
    {
        // Get compression segments
        $segments = $this->triggerService->getCompressionSegments($steps, $triggerResult);

        if (empty($segments)) {
            return $steps;
        }

        $compressedSteps = [];
        $lastIndex = 0;

        foreach ($segments as $segment) {
            // Add steps before this segment
            for ($i = $lastIndex; $i < $segment['start']; $i++) {
                if (isset($steps[$i])) {
                    $compressedSteps[] = $steps[$i];
                }
            }

            // Compress this segment
            $segmentSteps = array_slice($steps, $segment['start'], $segment['end'] - $segment['start'] + 1);
            $compressed = $this->compressor->compressSteps($segmentSteps);

            if ($compressed) {
                $compressedSteps[] = $compressed;

                // Store in memory for potential retrieval
                if ($sessionId) {
                    $this->memoryManager->storeCompressedContext($compressed);
                }
            } else {
                // If compression failed, keep original steps
                foreach ($segmentSteps as $step) {
                    $compressedSteps[] = $step;
                }
            }

            $lastIndex = $segment['end'] + 1;
        }

        // Add remaining steps after last segment
        for ($i = $lastIndex; $i < count($steps); $i++) {
            $compressedSteps[] = $steps[$i];
        }

        return $compressedSteps;
    }

    /**
     * Fallback management when compression is disabled
     */
    protected function fallbackManagement(array $steps): array
    {
        // First, identify persistent context
        $this->identifyPersistentContext($steps);

        // Score all steps by importance
        $this->scoreSteps($steps);

        // If within limit, return as-is
        if (count($steps) <= $this->maxSteps) {
            return $steps;
        }

        // Otherwise, intelligently trim
        return $this->intelligentTrim($steps);
    }

    /**
     * Identify context that should always be preserved
     */
    protected function identifyPersistentContext(array $steps): void
    {
        $this->persistentContext = [];

        foreach ($steps as $index => $step) {
            if ($this->isPersistent($step)) {
                $this->persistentContext[$index] = $step;
            }
        }
    }

    /**
     * Determine if a step should be persistent
     */
    protected function isPersistent(array $step): bool
    {
        // File operations are persistent
        if ($step['type'] === 'action' &&
            in_array($step['content']['action'] ?? '', ['write_file', 'run_command'])) {
            return true;
        }

        // Important observations (file created, key findings)
        if ($step['type'] === 'observation' &&
            (str_contains($step['content'], 'File written') ||
             str_contains($step['content'], 'created') ||
             str_contains($step['content'], 'saved'))) {
            return true;
        }

        return false;
    }

    /**
     * Score steps by importance
     */
    protected function scoreSteps(array $steps): void
    {
        $this->contextScores = [];

        foreach ($steps as $index => $step) {
            $score = 0;

            // Persistent context gets highest score
            if (isset($this->persistentContext[$index])) {
                $score += 100;
            }

            // Recent steps get higher scores
            $recency = $index / count($steps);
            $score += $recency * 50;

            // Final answers are important
            if ($step['type'] === 'action' &&
                ($step['content']['action'] ?? '') === 'final_answer') {
                $score += 75;
            }

            // Thoughts and evaluations provide context
            if ($step['type'] === 'thought') {
                $score += 25;
            }

            // Tool results are moderately important
            if ($step['type'] === 'observation') {
                $score += 30;
            }

            $this->contextScores[$index] = $score;
        }
    }

    /**
     * Intelligently trim context based on importance scores
     */
    protected function intelligentTrim(array $steps): array
    {
        // Always keep persistent context
        $kept = $this->persistentContext;
        $remainingSlots = $this->maxSteps - count($kept);

        // Sort non-persistent steps by score
        $nonPersistent = [];
        foreach ($steps as $index => $step) {
            if (! isset($this->persistentContext[$index])) {
                $nonPersistent[$index] = $this->contextScores[$index];
            }
        }
        arsort($nonPersistent);

        // Keep highest scoring non-persistent steps
        $count = 0;
        foreach ($nonPersistent as $index => $score) {
            if ($count >= $remainingSlots) {
                break;
            }
            $kept[$index] = $steps[$index];
            $count++;
        }

        // Sort by index to maintain chronological order
        ksort($kept);

        return array_values($kept);
    }

    /**
     * Create a summary of dropped context using compression
     */
    public function summarizeDroppedContext(array $allSteps, array $keptSteps): ?array
    {
        // Get indices of kept steps
        $keptIndices = [];
        foreach ($keptSteps as $i => $step) {
            // Find the original index
            foreach ($allSteps as $j => $originalStep) {
                if ($step === $originalStep) {
                    $keptIndices[] = $j;
                    break;
                }
            }
        }

        $droppedIndices = array_diff(array_keys($allSteps), $keptIndices);

        if (empty($droppedIndices)) {
            return null;
        }

        // Collect dropped steps
        $droppedSteps = [];
        foreach ($droppedIndices as $index) {
            $droppedSteps[] = $allSteps[$index];
        }

        // Use compressor to create a summary
        return $this->compressor->compressSteps($droppedSteps);
    }

    /**
     * Compress old context when approaching limits
     */
    public function compressOldContext(array $steps, ?string $sessionId = null): array
    {
        if (! ($this->config['enabled'] ?? true)) {
            return $this->legacyCompressOldContext($steps);
        }

        // Check for emergency compression needs
        if ($this->triggerService->isEmergencyCompression($steps)) {
            return $this->performEmergencyCompression($steps, $sessionId);
        }

        // Regular compression merging
        return $this->mergeCompressedContexts($steps);
    }

    /**
     * Perform emergency compression when context is critically large
     */
    protected function performEmergencyCompression(array $steps, ?string $sessionId): array
    {
        // Use aggressive compression strategy
        $triggerResult = [
            'should_compress' => true,
            'compression_strategy' => 'aggressive',
            'priority' => 'critical',
            'trigger_reasons' => ['Emergency compression triggered'],
        ];

        return $this->performIntelligentCompression($steps, $triggerResult, $sessionId);
    }

    /**
     * Merge existing compressed contexts
     */
    protected function mergeCompressedContexts(array $steps): array
    {
        $compressedContexts = [];
        $regularSteps = [];

        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'compressed_context') {
                $compressedContexts[] = $step;
            } else {
                $regularSteps[] = $step;
            }
        }

        // If we have multiple compressed contexts, merge them
        if (count($compressedContexts) > 1) {
            $merged = $this->compressor->mergeCompressedContexts($compressedContexts);

            return array_merge([$merged], $regularSteps);
        }

        return $steps;
    }

    /**
     * Legacy compression for backward compatibility
     */
    protected function legacyCompressOldContext(array $steps): array
    {
        $compressedContexts = [];
        $regularSteps = [];

        foreach ($steps as $step) {
            if (($step['type'] ?? '') === 'compressed_context') {
                $compressedContexts[] = $step;
            } else {
                $regularSteps[] = $step;
            }
        }

        if (count($compressedContexts) > 1) {
            $merged = $this->compressor->mergeCompressedContexts($compressedContexts);

            return array_merge([$merged], $regularSteps);
        }

        return $steps;
    }

    /**
     * Get compression recommendations for current context
     */
    public function getCompressionRecommendations(array $steps, ?string $sessionId = null): array
    {
        if (! $this->triggerService) {
            return [];
        }

        return $this->triggerService->getCompressionRecommendations($steps, $sessionId);
    }

    /**
     * Get memory usage statistics
     */
    public function getMemoryStats(): array
    {
        if (! $this->memoryManager) {
            return [];
        }

        return $this->memoryManager->getMemoryStats();
    }

    /**
     * Search for relevant compressed contexts
     */
    public function searchCompressedContexts(array $criteria, int $limit = 5): array
    {
        if (! $this->memoryManager) {
            return [];
        }

        return $this->memoryManager->searchContexts($criteria, $limit);
    }

    /**
     * Export context data for backup
     */
    public function exportContexts(?string $sessionId = null): array
    {
        if (! $this->memoryManager) {
            return [];
        }

        return $this->memoryManager->exportContexts($sessionId);
    }

    /**
     * Import context data from backup
     */
    public function importContexts(array $data): bool
    {
        if (! $this->memoryManager) {
            return false;
        }

        return $this->memoryManager->importContexts($data);
    }

    /**
     * Clean up old compressed contexts
     */
    public function cleanupOldContexts(): array
    {
        if (! $this->memoryManager) {
            return [];
        }

        return $this->memoryManager->cleanup();
    }
}
