<?php

namespace App\Agent\Context;

use App\Agent\LLM;

class ContextCompressor
{
    /**
     * Compress a group of conversation steps into a concise summary
     */
    public function compressSteps(array $steps): ?array
    {
        if (empty($steps)) {
            return null;
        }
        
        // Group steps by type for better compression
        $grouped = $this->groupSteps($steps);
        
        // If it's a small group, use simple compression
        if (count($steps) < 5) {
            return $this->simpleCompress($grouped);
        }
        
        // For larger groups, use LLM compression
        return $this->llmCompress($grouped);
    }
    
    /**
     * Group steps by their logical flow
     */
    protected function groupSteps(array $steps): array
    {
        $groups = [];
        $currentGroup = [];
        
        foreach ($steps as $step) {
            // Start new group on final_answer or after observation
            if ($step['type'] === 'action' && 
                ($step['content']['action'] ?? '') === 'final_answer') {
                if (!empty($currentGroup)) {
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                }
            }
            
            $currentGroup[] = $step;
            
            // End group after observation
            if ($step['type'] === 'observation') {
                if (count($currentGroup) >= 3) { // action + thought + observation
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                }
            }
        }
        
        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }
        
        return $groups;
    }
    
    /**
     * Simple compression for small step groups
     */
    protected function simpleCompress(array $groups): array
    {
        $summary = [];
        
        foreach ($groups as $group) {
            $action = null;
            $result = null;
            
            foreach ($group as $step) {
                if ($step['type'] === 'action') {
                    $action = $step['content']['action'] ?? 'unknown';
                    if ($action === 'final_answer') {
                        $result = $step['content']['action_input']['answer'] ?? '';
                    }
                } elseif ($step['type'] === 'observation') {
                    $result = $this->truncateObservation($step['content']);
                }
            }
            
            if ($action && $result) {
                $summary[] = "{$action}: {$result}";
            }
        }
        
        return [
            'type' => 'compressed_context',
            'content' => "Previous actions: " . implode('; ', $summary)
        ];
    }
    
    /**
     * LLM-based compression for larger contexts
     */
    protected function llmCompress(array $groups): array
    {
        $contextStr = "";
        
        foreach ($groups as $group) {
            foreach ($group as $step) {
                if ($step['type'] === 'action') {
                    $action = $step['content']['action'] ?? '';
                    $contextStr .= "Action: {$action}\n";
                } elseif ($step['type'] === 'thought') {
                    $contextStr .= "Thought: {$step['content']}\n";
                } elseif ($step['type'] === 'observation') {
                    $obs = $this->truncateObservation($step['content']);
                    $contextStr .= "Result: {$obs}\n";
                }
            }
            $contextStr .= "\n";
        }
        
        $prompt = "Compress this conversation context into a brief summary that preserves key information:

{$contextStr}

Create a JSON response with:
{
    \"summary\": \"Brief summary of what happened\",
    \"key_facts\": [\"Important facts to remember\"],
    \"files_created\": [\"List of files created/modified\"],
    \"user_preferences\": [\"Any user preferences mentioned\"]
}

Be very concise but preserve critical information.";

        $result = LLM::json($prompt);
        
        if (!$result) {
            return $this->simpleCompress($groups);
        }
        
        // Format the compressed context
        $compressed = "Context Summary: " . ($result['summary'] ?? '');
        
        if (!empty($result['key_facts'])) {
            $compressed .= " Key facts: " . implode(', ', $result['key_facts']) . ".";
        }
        
        if (!empty($result['files_created'])) {
            $compressed .= " Files: " . implode(', ', $result['files_created']) . ".";
        }
        
        return [
            'type' => 'compressed_context', 
            'content' => $compressed,
            'metadata' => $result
        ];
    }
    
    /**
     * Truncate long observations intelligently
     */
    protected function truncateObservation(string $observation, int $maxLength = 100): string
    {
        if (strlen($observation) <= $maxLength) {
            return $observation;
        }
        
        // Look for key information patterns
        if (preg_match('/File written to (.+)/', $observation, $matches)) {
            return "File written: " . basename($matches[1]);
        }
        
        if (preg_match('/File contents:(.{0,50})/s', $observation, $matches)) {
            return "File read: " . trim($matches[1]) . "...";
        }
        
        if (str_contains($observation, 'Error')) {
            return substr($observation, 0, $maxLength) . "...";
        }
        
        // Default truncation
        return substr($observation, 0, $maxLength - 3) . "...";
    }
    
    /**
     * Merge multiple compressed contexts
     */
    public function mergeCompressedContexts(array $contexts): array
    {
        $allFacts = [];
        $allFiles = [];
        $summaries = [];
        
        foreach ($contexts as $context) {
            if (isset($context['metadata'])) {
                $allFacts = array_merge($allFacts, $context['metadata']['key_facts'] ?? []);
                $allFiles = array_merge($allFiles, $context['metadata']['files_created'] ?? []);
                $summaries[] = $context['metadata']['summary'] ?? '';
            }
        }
        
        // Deduplicate
        $allFacts = array_unique($allFacts);
        $allFiles = array_unique($allFiles);
        
        $merged = "Previous context: " . implode('. ', array_filter($summaries));
        
        if (!empty($allFacts)) {
            $merged .= " Key facts: " . implode(', ', array_slice($allFacts, 0, 5)) . ".";
        }
        
        if (!empty($allFiles)) {
            $merged .= " Files worked with: " . implode(', ', $allFiles) . ".";
        }
        
        return [
            'type' => 'compressed_context',
            'content' => $merged,
            'metadata' => [
                'key_facts' => $allFacts,
                'files_created' => $allFiles
            ]
        ];
    }
}