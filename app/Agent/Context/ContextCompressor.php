<?php

namespace App\Agent\Context;

use App\Agent\Context\Services\MemoryManager;
use App\Agent\Context\Services\PerformanceMonitor;
use App\Agent\LLM;

class ContextCompressor
{
    protected MemoryManager $memoryManager;

    protected PerformanceMonitor $performanceMonitor;

    protected array $config;

    public function __construct()
    {
        $this->memoryManager = new MemoryManager;
        $this->performanceMonitor = new PerformanceMonitor;
        $this->config = config('app.context_compression', []);
    }

    /**
     * Compress a group of conversation steps with enhanced LLM-based summarization
     */
    public function compressSteps(array $steps): ?array
    {
        if (empty($steps)) {
            return null;
        }

        $startTime = microtime(true);

        // Analyze and categorize steps for compression
        $analysis = $this->analyzeSteps($steps);

        // Choose compression strategy based on analysis
        $compressionStrategy = $this->selectCompressionStrategy($analysis);

        $result = match ($compressionStrategy) {
            'simple' => $this->simpleCompress($this->groupSteps($steps)),
            'intelligent' => $this->intelligentCompress($steps, $analysis),
            'llm_enhanced' => $this->llmEnhancedCompress($steps, $analysis),
            default => $this->llmEnhancedCompress($steps, $analysis)
        };

        // Track performance metrics
        $compressionTime = microtime(true) - $startTime;
        $this->performanceMonitor->recordCompression($steps, $result, $compressionTime);

        return $result;
    }

    /**
     * Analyze steps to determine compression strategy and preserve critical information
     */
    protected function analyzeSteps(array $steps): array
    {
        $analysis = [
            'total_steps' => count($steps),
            'critical_steps' => [],
            'important_steps' => [],
            'compressible_steps' => [],
            'patterns' => [],
            'file_operations' => [],
            'user_preferences' => [],
            'decisions' => [],
            'errors' => [],
        ];

        foreach ($steps as $index => $step) {
            $priority = $this->classifyStepPriority($step);
            $category = $this->categorizeStep($step);

            if ($priority >= 100) {
                $analysis['critical_steps'][] = $index;
            } elseif ($priority >= 50) {
                $analysis['important_steps'][] = $index;
            } else {
                $analysis['compressible_steps'][] = $index;
            }

            // Track specific categories
            if ($category === 'file_operation') {
                $analysis['file_operations'][] = $index;
            } elseif ($category === 'user_preference') {
                $analysis['user_preferences'][] = $index;
            } elseif ($category === 'decision') {
                $analysis['decisions'][] = $index;
            } elseif ($category === 'error') {
                $analysis['errors'][] = $index;
            }
        }

        // Detect patterns
        $analysis['patterns'] = $this->detectPatterns($steps);

        return $analysis;
    }

    /**
     * Select optimal compression strategy based on analysis
     */
    protected function selectCompressionStrategy(array $analysis): string
    {
        $totalSteps = $analysis['total_steps'];
        $criticalRatio = count($analysis['critical_steps']) / $totalSteps;

        if ($totalSteps < 5) {
            return 'simple';
        }

        if ($criticalRatio > 0.7) {
            return 'intelligent';
        }

        return 'llm_enhanced';
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
                if (! empty($currentGroup)) {
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

        if (! empty($currentGroup)) {
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
            'content' => 'Previous actions: '.implode('; ', $summary),
        ];
    }

    /**
     * Enhanced LLM-based compression with intelligent context preservation
     */
    protected function llmEnhancedCompress(array $steps, array $analysis): array
    {
        // Prepare structured context for LLM
        $contextData = $this->prepareContextForLLM($steps, $analysis);

        $prompt = $this->buildCompressionPrompt($contextData);

        $llmConfig = $this->config['llm'] ?? [];
        $result = LLM::json($prompt, $llmConfig['max_tokens'] ?? 1000);

        if (! $result) {
            // Fallback to intelligent compression
            return $this->intelligentCompress($steps, $analysis);
        }

        // Validate and enhance the LLM result
        $validatedResult = $this->validateAndEnhanceCompression($result, $analysis);

        // Store in memory for potential retrieval
        $this->memoryManager->storeCompressedContext($validatedResult);

        return $validatedResult;
    }

    /**
     * Prepare context data in structured format for LLM processing
     */
    protected function prepareContextForLLM(array $steps, array $analysis): array
    {
        $contextData = [
            'critical_information' => [],
            'file_operations' => [],
            'user_preferences' => [],
            'key_decisions' => [],
            'errors_and_solutions' => [],
            'tool_results' => [],
            'conversation_flow' => [],
        ];

        foreach ($steps as $index => $step) {
            if (in_array($index, $analysis['critical_steps'])) {
                $contextData['critical_information'][] = $this->formatStepForLLM($step);
            }

            if (in_array($index, $analysis['file_operations'])) {
                $contextData['file_operations'][] = $this->extractFileOperation($step);
            }

            if (in_array($index, $analysis['user_preferences'])) {
                $contextData['user_preferences'][] = $this->extractUserPreference($step);
            }

            if (in_array($index, $analysis['decisions'])) {
                $contextData['key_decisions'][] = $this->extractDecision($step);
            }

            if (in_array($index, $analysis['errors'])) {
                $contextData['errors_and_solutions'][] = $this->extractError($step);
            }

            // Add to conversation flow if important
            if (in_array($index, $analysis['important_steps'])) {
                $contextData['conversation_flow'][] = $this->formatStepForLLM($step);
            }
        }

        return $contextData;
    }

    /**
     * Build comprehensive compression prompt for LLM
     */
    protected function buildCompressionPrompt(array $contextData): string
    {
        $prompt = "You are compressing agent conversation context. Preserve ALL critical information while creating a concise summary.\n\n";

        if (! empty($contextData['critical_information'])) {
            $prompt .= "CRITICAL INFORMATION (must preserve exactly):\n";
            foreach ($contextData['critical_information'] as $info) {
                $prompt .= "- {$info}\n";
            }
            $prompt .= "\n";
        }

        if (! empty($contextData['file_operations'])) {
            $prompt .= "FILE OPERATIONS:\n";
            foreach ($contextData['file_operations'] as $op) {
                $prompt .= "- {$op}\n";
            }
            $prompt .= "\n";
        }

        if (! empty($contextData['user_preferences'])) {
            $prompt .= "USER PREFERENCES:\n";
            foreach ($contextData['user_preferences'] as $pref) {
                $prompt .= "- {$pref}\n";
            }
            $prompt .= "\n";
        }

        if (! empty($contextData['key_decisions'])) {
            $prompt .= "KEY DECISIONS:\n";
            foreach ($contextData['key_decisions'] as $decision) {
                $prompt .= "- {$decision}\n";
            }
            $prompt .= "\n";
        }

        if (! empty($contextData['errors_and_solutions'])) {
            $prompt .= "ERRORS & SOLUTIONS:\n";
            foreach ($contextData['errors_and_solutions'] as $error) {
                $prompt .= "- {$error}\n";
            }
            $prompt .= "\n";
        }

        if (! empty($contextData['conversation_flow'])) {
            $prompt .= "CONVERSATION FLOW:\n";
            foreach (array_slice($contextData['conversation_flow'], -10) as $step) {
                $prompt .= "- {$step}\n";
            }
        }

        $prompt .= "\nCreate a JSON response with this exact structure:
{
    \"executive_summary\": \"High-level summary of what was accomplished\",
    \"critical_facts\": [\"List of facts that must not be lost\"],
    \"file_operations\": [\"Precise list of files created/modified/deleted with paths\"],
    \"user_preferences\": [\"User settings, preferences, or requirements mentioned\"],
    \"key_decisions\": [\"Important decisions made and their rationale\"],
    \"errors_encountered\": [\"Errors found and how they were resolved\"],
    \"current_state\": \"Description of the current state/progress\",
    \"next_steps\": [\"Logical next steps or pending actions\"],
    \"compression_metadata\": {
        \"original_steps\": ".count($contextData['conversation_flow']).',
        "compression_ratio": "estimate like 0.75",
        "information_loss_risk": "low/medium/high"
    }
}

Prioritize accuracy over brevity. Every critical piece of information must be preserved.';

        return $prompt;
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
            return 'File written: '.basename($matches[1]);
        }

        if (preg_match('/File contents:(.{0,50})/s', $observation, $matches)) {
            return 'File read: '.trim($matches[1]).'...';
        }

        if (str_contains($observation, 'Error')) {
            return substr($observation, 0, $maxLength).'...';
        }

        // Default truncation
        return substr($observation, 0, $maxLength - 3).'...';
    }

    /**
     * Intelligent compression fallback when LLM compression fails
     */
    protected function intelligentCompress(array $steps, array $analysis): array
    {
        $preserved = [];
        $summary = [];

        // Always preserve critical steps
        foreach ($analysis['critical_steps'] as $index) {
            $preserved[] = $this->formatStepForLLM($steps[$index]);
        }

        // Summarize compressible steps
        $compressibleCount = count($analysis['compressible_steps']);
        if ($compressibleCount > 0) {
            $summary[] = "Compressed {$compressibleCount} routine steps";
        }

        // Add file operations summary
        if (! empty($analysis['file_operations'])) {
            $files = [];
            foreach ($analysis['file_operations'] as $index) {
                $files[] = $this->extractFileOperation($steps[$index]);
            }
            $summary[] = 'Files: '.implode(', ', $files);
        }

        $content = implode('. ', array_merge($preserved, $summary));

        return [
            'type' => 'compressed_context',
            'content' => $content,
            'metadata' => [
                'compression_type' => 'intelligent',
                'preserved_count' => count($preserved),
                'compressed_count' => $compressibleCount,
                'file_operations' => $analysis['file_operations'] ?? [],
            ],
        ];
    }

    /**
     * Classify step priority for compression decisions
     */
    protected function classifyStepPriority(array $step): int
    {
        $content = $step['content'] ?? '';
        $type = $step['type'] ?? '';

        // Critical priority (100+)
        if ($type === 'action') {
            $action = is_array($content) ? ($content['action'] ?? '') : '';
            if (in_array($action, ['write_file', 'run_command', 'final_answer'])) {
                return 100;
            }
        }

        if ($type === 'observation' && (
            str_contains($content, 'File written') ||
            str_contains($content, 'created') ||
            str_contains($content, 'Error:') ||
            str_contains($content, 'saved')
        )) {
            return 100;
        }

        // Important priority (50-99)
        if ($type === 'thought' || $type === 'observation') {
            return 60;
        }

        // Default compressible priority
        return 25;
    }

    /**
     * Categorize step for targeted processing
     */
    protected function categorizeStep(array $step): ?string
    {
        $content = $step['content'] ?? '';
        $type = $step['type'] ?? '';

        if ($type === 'action') {
            $action = is_array($content) ? ($content['action'] ?? '') : '';
            if (in_array($action, ['write_file', 'read_file', 'run_command'])) {
                return 'file_operation';
            }
        }

        if (is_string($content)) {
            if (str_contains($content, 'preference') || str_contains($content, 'setting')) {
                return 'user_preference';
            }
            if (str_contains($content, 'decided') || str_contains($content, 'chose')) {
                return 'decision';
            }
            if (str_contains($content, 'Error:') || str_contains($content, 'failed')) {
                return 'error';
            }
        }

        return null;
    }

    /**
     * Detect patterns in conversation steps
     */
    protected function detectPatterns(array $steps): array
    {
        $patterns = [];
        $actionSequences = [];

        foreach ($steps as $step) {
            if ($step['type'] === 'action') {
                $action = is_array($step['content']) ? ($step['content']['action'] ?? '') : '';
                $actionSequences[] = $action;
            }
        }

        // Detect repetitive action patterns
        $sequenceLength = count($actionSequences);
        for ($i = 0; $i < $sequenceLength - 2; $i++) {
            $pattern = array_slice($actionSequences, $i, 3);
            $patternStr = implode('->', $pattern);

            if (! isset($patterns[$patternStr])) {
                $patterns[$patternStr] = 1;
            } else {
                $patterns[$patternStr]++;
            }
        }

        // Return patterns that appear more than once
        return array_filter($patterns, fn ($count) => $count > 1);
    }

    /**
     * Format step for LLM consumption
     */
    protected function formatStepForLLM(array $step): string
    {
        $type = $step['type'] ?? 'unknown';
        $content = $step['content'] ?? '';

        if ($type === 'action') {
            $action = is_array($content) ? ($content['action'] ?? '') : '';
            $input = is_array($content) ? ($content['action_input'] ?? []) : [];

            if ($action === 'write_file') {
                $filename = $input['filename'] ?? $input['file_path'] ?? 'unknown';

                return "Action: Created/modified file {$filename}";
            } elseif ($action === 'run_command') {
                $command = $input['command'] ?? 'unknown';

                return "Action: Executed command: {$command}";
            } else {
                return "Action: {$action}";
            }
        } elseif ($type === 'observation') {
            return 'Result: '.$this->truncateObservation($content, 200);
        } elseif ($type === 'thought') {
            return 'Thought: '.(is_string($content) ? substr($content, 0, 150) : '');
        }

        return "{$type}: ".(is_string($content) ? substr($content, 0, 100) : '');
    }

    /**
     * Extract file operation details
     */
    protected function extractFileOperation(array $step): string
    {
        $content = $step['content'] ?? '';

        if ($step['type'] === 'action' && is_array($content)) {
            $action = $content['action'] ?? '';
            $input = $content['action_input'] ?? [];

            if ($action === 'write_file') {
                $filename = $input['filename'] ?? $input['file_path'] ?? 'unknown';

                return "Created/modified: {$filename}";
            } elseif ($action === 'read_file') {
                $filename = $input['filename'] ?? $input['file_path'] ?? 'unknown';

                return "Read: {$filename}";
            }
        }

        if (is_string($content) && str_contains($content, 'File written')) {
            preg_match('/File written to (.+)/', $content, $matches);

            return 'Written: '.($matches[1] ?? 'unknown');
        }

        return 'File operation';
    }

    /**
     * Extract user preference information
     */
    protected function extractUserPreference(array $step): string
    {
        $content = $step['content'] ?? '';

        if (is_string($content)) {
            // Simple extraction - could be enhanced with NLP
            if (preg_match('/prefer[s]? (.+)/i', $content, $matches)) {
                return 'Preference: '.$matches[1];
            }
            if (preg_match('/setting[s]? (.+)/i', $content, $matches)) {
                return 'Setting: '.$matches[1];
            }
        }

        return 'User preference mentioned';
    }

    /**
     * Extract decision information
     */
    protected function extractDecision(array $step): string
    {
        $content = $step['content'] ?? '';

        if (is_string($content)) {
            if (preg_match('/decided to (.+)/i', $content, $matches)) {
                return 'Decision: '.$matches[1];
            }
            if (preg_match('/chose (.+)/i', $content, $matches)) {
                return 'Chose: '.$matches[1];
            }
        }

        return 'Decision made';
    }

    /**
     * Extract error information
     */
    protected function extractError(array $step): string
    {
        $content = $step['content'] ?? '';

        if (is_string($content)) {
            if (preg_match('/Error: (.+)/i', $content, $matches)) {
                return 'Error: '.substr($matches[1], 0, 100);
            }
            if (str_contains($content, 'failed')) {
                return 'Failure: '.substr($content, 0, 100);
            }
        }

        return 'Error encountered';
    }

    /**
     * Validate and enhance LLM compression result
     */
    protected function validateAndEnhanceCompression(array $result, array $analysis): array
    {
        // Ensure required fields exist
        $validated = [
            'type' => 'compressed_context',
            'content' => '',
            'metadata' => $result,
        ];

        // Build content from LLM result
        $content = $result['executive_summary'] ?? 'Context compressed';

        if (! empty($result['critical_facts'])) {
            $content .= ' Critical facts: '.implode(', ', $result['critical_facts']).'.';
        }

        if (! empty($result['file_operations'])) {
            $content .= ' Files: '.implode(', ', $result['file_operations']).'.';
        }

        if (! empty($result['current_state'])) {
            $content .= ' Current state: '.$result['current_state'].'.';
        }

        $validated['content'] = $content;

        // Add compression metadata
        $validated['metadata']['compression_timestamp'] = time();
        $validated['metadata']['original_step_count'] = $analysis['total_steps'];
        $validated['metadata']['compression_version'] = '2.0';

        return $validated;
    }

    /**
     * Merge multiple compressed contexts with enhanced intelligence
     */
    public function mergeCompressedContexts(array $contexts): array
    {
        if (empty($contexts)) {
            return [];
        }

        /** @var array<string, list<mixed>> $mergedMetadata */
        $mergedMetadata = [
            'critical_facts' => [],
            'file_operations' => [],
            'user_preferences' => [],
            'key_decisions' => [],
            'errors_encountered' => [],
            'executive_summaries' => [],
        ];

        // Collect all information from contexts
        foreach ($contexts as $context) {
            $metadata = $context['metadata'] ?? [];

            foreach ($mergedMetadata as $key => &$array) {
                if (isset($metadata[$key])) {
                    $array = array_merge($array, (array) $metadata[$key]);
                }
            }

            // Collect executive summaries
            if (isset($metadata['executive_summary'])) {
                $mergedMetadata['executive_summaries'][] = $metadata['executive_summary'];
            }
        }

        // Deduplicate arrays
        foreach ($mergedMetadata as &$array) {
            $array = array_unique($array);
        }

        // Build merged content
        $content = 'Merged context: '.implode('. ', $mergedMetadata['executive_summaries']);

        if (! empty($mergedMetadata['critical_facts'])) {
            $content .= ' Key facts: '.implode(', ', array_slice($mergedMetadata['critical_facts'], 0, 5)).'.';
        }

        if (! empty($mergedMetadata['file_operations'])) {
            $content .= ' Files: '.implode(', ', $mergedMetadata['file_operations']).'.';
        }

        return [
            'type' => 'compressed_context',
            'content' => $content,
            'metadata' => array_merge($mergedMetadata, [
                'merge_timestamp' => time(),
                'merged_context_count' => count($contexts),
                'compression_version' => '2.0',
            ]),
        ];
    }

    /**
     * Simple compression for backward compatibility
     */
    protected function simpleCompressSteps(array $steps): array
    {
        if (empty($steps)) {
            return [
                'type' => 'compressed_context',
                'content' => 'No context to compress',
            ];
        }

        $summary = [];
        $files = [];

        foreach ($steps as $step) {
            if ($step['type'] === 'action') {
                $action = is_array($step['content']) ? ($step['content']['action'] ?? 'unknown') : 'unknown';
                if ($action === 'write_file' || $action === 'read_file') {
                    $input = $step['content']['action_input'] ?? [];
                    $filename = $input['filename'] ?? $input['file_path'] ?? 'unknown';
                    $files[] = "{$action}: {$filename}";
                } else {
                    $summary[] = "Action: {$action}";
                }
            } elseif ($step['type'] === 'observation') {
                $obs = $this->truncateObservation($step['content'], 50);
                $summary[] = "Result: {$obs}";
            }
        }

        $content = implode('; ', array_merge($summary, $files));

        return [
            'type' => 'compressed_context',
            'content' => $content,
            'metadata' => [
                'compression_type' => 'simple',
                'step_count' => count($steps),
                'file_operations' => $files,
            ],
        ];
    }
}
