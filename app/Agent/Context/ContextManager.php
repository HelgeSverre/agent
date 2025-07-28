<?php

namespace App\Agent\Context;

class ContextManager
{
    protected int $maxSteps = 15;
    protected array $persistentContext = [];
    protected array $contextScores = [];
    protected ?ContextCompressor $compressor = null;
    
    public function __construct()
    {
        $this->compressor = new ContextCompressor();
    }
    
    /**
     * Manage context window by intelligently trimming and preserving important information
     */
    public function manageContext(array $steps): array
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
            if (!isset($this->persistentContext[$index])) {
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
    public function compressOldContext(array $steps): array
    {
        // If we have compressed contexts, try to merge them
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
}