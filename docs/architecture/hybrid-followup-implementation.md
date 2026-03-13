# Hybrid Follow-up Recognition System - Implementation Guide

## Class Design & Implementation

### Core Classes Structure

```
app/Agent/Chat/
├── FollowUpRecognizer.php          # Main orchestrator
├── PatternMatcher.php              # Fast pattern matching
├── ContextAnalyzer.php             # Medium-complexity analysis
├── LLMClassifier.php               # Fallback LLM processing
├── ConversationContext.php         # Context management
├── RecognitionResult.php           # Result value object
├── PatternRules.php                # Pattern rule definitions
└── Metrics/
    └── RecognitionMetrics.php      # Performance tracking
```

## 1. FollowUpRecognizer.php (Main Orchestrator)

```php
<?php

namespace App\Agent\Chat;

use App\Agent\Chat\Metrics\RecognitionMetrics;

class FollowUpRecognizer
{
    private PatternMatcher $patternMatcher;
    private ContextAnalyzer $contextAnalyzer;
    private LLMClassifier $llmClassifier;
    private ConversationContext $context;
    private RecognitionMetrics $metrics;

    public function __construct(ConversationContext $context)
    {
        $this->context = $context;
        $this->patternMatcher = new PatternMatcher();
        $this->contextAnalyzer = new ContextAnalyzer($context);
        $this->llmClassifier = new LLMClassifier();
        $this->metrics = new RecognitionMetrics();
    }

    public function analyze(string $input, string $previousResponse): RecognitionResult
    {
        $startTime = microtime(true);

        // Phase 1: Fast Pattern Matching (1ms target)
        $patternResult = $this->patternMatcher->match($input, $previousResponse);
        if ($patternResult->isMatch()) {
            $result = $this->createResult($input, $patternResult, 'pattern', $startTime);
            $this->metrics->recordPatternMatch($result);
            return $result;
        }

        // Phase 2: Context Analysis (50ms target)
        $contextResult = $this->contextAnalyzer->analyze($input, $previousResponse);
        if ($contextResult->isFollowUp()) {
            $result = $this->createResult($input, $contextResult, 'context', $startTime);
            $this->metrics->recordContextMatch($result);
            return $result;
        }

        // Phase 3: LLM Fallback (1000ms target)
        $llmResult = $this->llmClassifier->classify($input, $previousResponse, $this->context);
        $result = $this->createResult($input, $llmResult, 'llm', $startTime);
        $this->metrics->recordLLMFallback($result);

        return $result;
    }

    private function createResult(
        string $input,
        $analysisResult,
        string $method,
        float $startTime
    ): RecognitionResult {
        return new RecognitionResult(
            originalInput: $input,
            enhancedInput: $analysisResult->getEnhancedInput(),
            isFollowUp: $analysisResult->isFollowUp(),
            confidence: $analysisResult->getConfidence(),
            method: $method,
            processingTime: microtime(true) - $startTime,
            context: $analysisResult->getContext()
        );
    }
}
```

## 2. PatternMatcher.php (Fast Path)

```php
<?php

namespace App\Agent\Chat;

class PatternMatcher
{
    private PatternRules $rules;

    public function __construct()
    {
        $this->rules = new PatternRules();
    }

    public function match(string $input, string $previousResponse): PatternResult
    {
        $normalizedInput = $this->normalizeInput($input);

        // Check each pattern category in order of confidence
        foreach ($this->rules->getHighConfidencePatterns() as $pattern) {
            if ($match = $this->matchPattern($normalizedInput, $pattern)) {
                return $this->createFollowUpResult($input, $match, 0.95);
            }
        }

        foreach ($this->rules->getMediumConfidencePatterns() as $pattern) {
            if ($match = $this->matchPattern($normalizedInput, $pattern)) {
                return $this->createFollowUpResult($input, $match, 0.85);
            }
        }

        foreach ($this->rules->getLowConfidencePatterns() as $pattern) {
            if ($match = $this->matchPattern($normalizedInput, $pattern)) {
                return $this->createFollowUpResult($input, $match, 0.70);
            }
        }

        return new PatternResult(false, $input, 0.0);
    }

    private function normalizeInput(string $input): string
    {
        return trim(strtolower($input));
    }

    private function matchPattern(string $input, array $pattern): ?array
    {
        if (preg_match($pattern['regex'], $input, $matches)) {
            return [
                'type' => $pattern['type'],
                'matches' => $matches,
                'template' => $pattern['template'] ?? null
            ];
        }

        return null;
    }

    private function createFollowUpResult(string $input, array $match, float $confidence): PatternResult
    {
        $enhancedInput = $this->enhanceWithPattern($input, $match);

        return new PatternResult(
            isMatch: true,
            enhancedInput: $enhancedInput,
            confidence: $confidence,
            matchType: $match['type']
        );
    }

    private function enhanceWithPattern(string $input, array $match): string
    {
        switch ($match['type']) {
            case 'pronoun_reference':
                return $this->handlePronounReference($input, $match);
            case 'continuation_command':
                return $this->handleContinuation($input, $match);
            case 'clarification_request':
                return $this->handleClarification($input, $match);
            default:
                return $input;
        }
    }

    private function handlePronounReference(string $input, array $match): string
    {
        // Examples:
        // "fix it" -> "fix it (referring to the previous result)"
        // "update that" -> "update that (referring to the previous output)"

        $context = match($match['matches'][0]) {
            'it', 'this' => '(referring to the previous result)',
            'that', 'those' => '(referring to the previous output)',
            'them', 'these' => '(referring to the previous items)',
            default => '(referring to the previous context)'
        };

        return $input . ' ' . $context;
    }

    private function handleContinuation(string $input, array $match): string
    {
        // Examples:
        // "another one" -> "create another one (similar to the previous)"
        // "more examples" -> "show more examples (like the previous ones)"

        return $input . ' (continuing from the previous task)';
    }

    private function handleClarification(string $input, array $match): string
    {
        // Examples:
        // "what about X" -> "what about X (in the context of our previous discussion)"

        return $input . ' (in the context of our previous discussion)';
    }
}
```

## 3. PatternRules.php (Pattern Definitions)

```php
<?php

namespace App\Agent\Chat;

class PatternRules
{
    public function getHighConfidencePatterns(): array
    {
        return [
            // Definitive pronouns with action verbs
            [
                'type' => 'pronoun_reference',
                'regex' => '/^(yes,?\s*)?(do|make|create|show|fix|update|modify|change|edit|delete|remove)\s+(it|that|this)(\s|$|\.|!|\?)/i',
                'confidence' => 0.95
            ],

            // Simple continuations
            [
                'type' => 'continuation_command',
                'regex' => '/^(another|more)\s+(one|example|option|version|way)(\s|$|\.|!|\?)/i',
                'confidence' => 0.95
            ],

            // Affirmative responses
            [
                'type' => 'continuation_command',
                'regex' => '/^(yes|ok|sure|go\s+ahead|proceed|continue)(\s|$|\.|!|,)/i',
                'confidence' => 0.95
            ],

            // Negative responses
            [
                'type' => 'continuation_command',
                'regex' => '/^(no|skip|cancel|stop|nevermind|never\s+mind)(\s|$|\.|!|,)/i',
                'confidence' => 0.95
            ]
        ];
    }

    public function getMediumConfidencePatterns(): array
    {
        return [
            // Reference with descriptors
            [
                'type' => 'pronoun_reference',
                'regex' => '/^(the\s+same|similar|like\s+that|like\s+this)\b/i',
                'confidence' => 0.85
            ],

            // Clarification requests
            [
                'type' => 'clarification_request',
                'regex' => '/^(what|how)\s+about\b/i',
                'confidence' => 0.85
            ],

            // Follow-up questions
            [
                'type' => 'clarification_request',
                'regex' => '/^(can\s+you|could\s+you|would\s+you)\s+(also|now|then)\b/i',
                'confidence' => 0.80
            ]
        ];
    }

    public function getLowConfidencePatterns(): array
    {
        return [
            // General references
            [
                'type' => 'pronoun_reference',
                'regex' => '/\b(it|this|that|them|those|these)\b/',
                'confidence' => 0.70
            ],

            // Continuation words
            [
                'type' => 'continuation_command',
                'regex' => '/^(also|additionally|furthermore|moreover)\b/i',
                'confidence' => 0.70
            ]
        ];
    }
}
```

## 4. ContextAnalyzer.php (Medium Path)

```php
<?php

namespace App\Agent\Chat;

class ContextAnalyzer
{
    private ConversationContext $context;

    public function __construct(ConversationContext $context)
    {
        $this->context = $context;
    }

    public function analyze(string $input, string $previousResponse): ContextResult
    {
        $score = 0;
        $contextElements = [];

        // Topic similarity analysis
        $topicScore = $this->analyzeTopicSimilarity($input, $previousResponse);
        $score += $topicScore * 0.4;

        // Entity reference detection
        $entityScore = $this->analyzeEntityReferences($input);
        $score += $entityScore * 0.3;
        $contextElements['entities'] = $this->getReferencedEntities($input);

        // Action continuation analysis
        $actionScore = $this->analyzeActionContinuation($input);
        $score += $actionScore * 0.3;
        $contextElements['actions'] = $this->getActionContext($input);

        $isFollowUp = $score >= 0.6; // Threshold for follow-up classification

        if ($isFollowUp) {
            $enhancedInput = $this->enhanceWithContext($input, $contextElements);
            return new ContextResult(true, $enhancedInput, $score, $contextElements);
        }

        return new ContextResult(false, $input, $score);
    }

    private function analyzeTopicSimilarity(string $input, string $previousResponse): float
    {
        $inputTokens = $this->tokenize($input);
        $responseTokens = $this->tokenize($previousResponse);

        $intersection = array_intersect($inputTokens, $responseTokens);
        $union = array_unique(array_merge($inputTokens, $responseTokens));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    private function analyzeEntityReferences(string $input): float
    {
        $entities = $this->context->getTrackedEntities();
        $score = 0.0;

        foreach ($entities as $entity) {
            if (stripos($input, $entity['value']) !== false) {
                $score += $entity['relevance'];
            }
        }

        return min(1.0, $score);
    }

    private function analyzeActionContinuation(string $input): float
    {
        $lastAction = $this->context->getLastAction();
        if (!$lastAction) {
            return 0.0;
        }

        $continuationWords = ['test', 'explain', 'show', 'demonstrate', 'improve', 'fix'];
        $actionWords = $this->extractActionWords($input);

        foreach ($actionWords as $word) {
            if (in_array($word, $continuationWords)) {
                return 0.8;
            }
        }

        return 0.0;
    }

    private function enhanceWithContext(string $input, array $contextElements): string
    {
        $contextParts = [];

        if (!empty($contextElements['entities'])) {
            $entities = implode(', ', array_column($contextElements['entities'], 'value'));
            $contextParts[] = "referring to: {$entities}";
        }

        if (!empty($contextElements['actions'])) {
            $action = $contextElements['actions']['type'];
            $contextParts[] = "continuing from: {$action}";
        }

        if (!empty($contextParts)) {
            return $input . ' (' . implode(', ', $contextParts) . ')';
        }

        return $input;
    }

    private function tokenize(string $text): array
    {
        // Simple tokenization - remove stop words and extract meaningful terms
        $stopWords = ['the', 'is', 'at', 'which', 'on', 'and', 'or', 'but', 'in', 'with', 'to', 'for', 'of', 'as', 'by'];
        $tokens = array_filter(
            array_map('strtolower', preg_split('/\W+/', $text)),
            fn($token) => strlen($token) > 2 && !in_array($token, $stopWords)
        );

        return array_values($tokens);
    }

    private function getReferencedEntities(string $input): array
    {
        $entities = [];
        $trackedEntities = $this->context->getTrackedEntities();

        foreach ($trackedEntities as $entity) {
            if (stripos($input, $entity['value']) !== false) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    private function getActionContext(string $input): array
    {
        $actionWords = $this->extractActionWords($input);
        $lastAction = $this->context->getLastAction();

        return [
            'type' => $lastAction['type'] ?? 'unknown',
            'current_words' => $actionWords,
            'is_continuation' => !empty($actionWords)
        ];
    }

    private function extractActionWords(string $input): array
    {
        $actionVerbs = ['test', 'show', 'explain', 'create', 'make', 'build', 'fix', 'update', 'modify', 'improve'];
        $words = preg_split('/\W+/', strtolower($input));

        return array_intersect($words, $actionVerbs);
    }
}
```

## 5. ConversationContext.php (Context Management)

```php
<?php

namespace App\Agent\Chat;

class ConversationContext
{
    private array $exchanges = [];
    private array $trackedEntities = [];
    private ?array $lastAction = null;
    private int $maxExchanges = 10;

    public function addExchange(string $input, string $response): void
    {
        $this->exchanges[] = [
            'input' => $input,
            'response' => $response,
            'timestamp' => time(),
            'entities' => $this->extractEntities($input . ' ' . $response)
        ];

        // Keep only recent exchanges
        if (count($this->exchanges) > $this->maxExchanges) {
            array_shift($this->exchanges);
        }

        // Update tracked entities
        $this->updateTrackedEntities();

        // Update last action
        $this->updateLastAction($input, $response);
    }

    public function getTrackedEntities(): array
    {
        return $this->trackedEntities;
    }

    public function getLastAction(): ?array
    {
        return $this->lastAction;
    }

    public function getRecentContext(int $exchanges = 3): string
    {
        $recent = array_slice($this->exchanges, -$exchanges);
        $context = [];

        foreach ($recent as $exchange) {
            $context[] = "User: " . $this->truncate($exchange['input'], 100);
            $context[] = "Assistant: " . $this->truncate($exchange['response'], 150);
        }

        return implode("\n", $context);
    }

    private function extractEntities(string $text): array
    {
        $entities = [];

        // File paths
        if (preg_match_all('/\b[\w\/\-\.]+\.(php|js|html|css|json|md|txt)\b/i', $text, $matches)) {
            foreach ($matches[0] as $file) {
                $entities[] = ['type' => 'file', 'value' => $file];
            }
        }

        // URLs
        if (preg_match_all('/https?:\/\/[^\s]+/i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $entities[] = ['type' => 'url', 'value' => $url];
            }
        }

        // Technical terms (class names, function names)
        if (preg_match_all('/\b[A-Z][a-zA-Z0-9]*(?:Service|Manager|Handler|Controller|Tool)\b/', $text, $matches)) {
            foreach ($matches[0] as $term) {
                $entities[] = ['type' => 'class', 'value' => $term];
            }
        }

        return $entities;
    }

    private function updateTrackedEntities(): void
    {
        $allEntities = [];

        // Collect entities from recent exchanges
        foreach ($this->exchanges as $exchange) {
            $allEntities = array_merge($allEntities, $exchange['entities']);
        }

        // Count occurrences and calculate relevance
        $entityCounts = [];
        foreach ($allEntities as $entity) {
            $key = $entity['type'] . ':' . $entity['value'];
            $entityCounts[$key] = ($entityCounts[$key] ?? 0) + 1;
        }

        // Update tracked entities with relevance scores
        $this->trackedEntities = [];
        foreach ($entityCounts as $key => $count) {
            [$type, $value] = explode(':', $key, 2);
            $relevance = min(1.0, $count * 0.3); // Max relevance of 1.0

            $this->trackedEntities[] = [
                'type' => $type,
                'value' => $value,
                'count' => $count,
                'relevance' => $relevance
            ];
        }

        // Sort by relevance
        usort($this->trackedEntities, fn($a, $b) => $b['relevance'] <=> $a['relevance']);

        // Keep only top entities
        $this->trackedEntities = array_slice($this->trackedEntities, 0, 20);
    }

    private function updateLastAction(string $input, string $response): void
    {
        // Extract action from input
        $actionWords = ['create', 'make', 'build', 'write', 'update', 'modify', 'fix', 'delete', 'remove', 'analyze', 'test'];
        $words = preg_split('/\W+/', strtolower($input));

        foreach ($actionWords as $action) {
            if (in_array($action, $words)) {
                $this->lastAction = [
                    'type' => $action,
                    'input' => $input,
                    'timestamp' => time()
                ];
                break;
            }
        }
    }

    private function truncate(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
    }
}
```

## 6. Result Value Objects

```php
<?php

namespace App\Agent\Chat;

class RecognitionResult
{
    public function __construct(
        private string $originalInput,
        private string $enhancedInput,
        private bool $isFollowUp,
        private float $confidence,
        private string $method,
        private float $processingTime,
        private array $context = []
    ) {}

    public function getOriginalInput(): string { return $this->originalInput; }
    public function getEnhancedInput(): string { return $this->enhancedInput; }
    public function isFollowUp(): bool { return $this->isFollowUp; }
    public function getConfidence(): float { return $this->confidence; }
    public function getMethod(): string { return $this->method; }
    public function getProcessingTime(): float { return $this->processingTime; }
    public function getContext(): array { return $this->context; }
}

class PatternResult
{
    public function __construct(
        private bool $isMatch,
        private string $enhancedInput,
        private float $confidence,
        private ?string $matchType = null
    ) {}

    public function isMatch(): bool { return $this->isMatch; }
    public function getEnhancedInput(): string { return $this->enhancedInput; }
    public function getConfidence(): float { return $this->confidence; }
    public function isFollowUp(): bool { return $this->isMatch; }
}

class ContextResult
{
    public function __construct(
        private bool $isFollowUp,
        private string $enhancedInput,
        private float $confidence,
        private array $context = []
    ) {}

    public function isFollowUp(): bool { return $this->isFollowUp; }
    public function getEnhancedInput(): string { return $this->enhancedInput; }
    public function getConfidence(): float { return $this->confidence; }
    public function getContext(): array { return $this->context; }
}
```

## 7. LLMClassifier.php (Fallback)

```php
<?php

namespace App\Agent\Chat;

use App\Agent\LLM;

class LLMClassifier
{
    public function classify(
        string $input,
        string $previousResponse,
        ConversationContext $context
    ): LLMResult {
        // Optimized prompt for faster processing
        $recentContext = $context->getRecentContext(2); // Only last 2 exchanges

        $prompt = "Analyze if the input is a follow-up to the previous conversation.

Context (last exchanges):
{$recentContext}

New input: {$input}

Return JSON:
{
    \"is_follow_up\": true/false,
    \"enhanced_input\": \"input with minimal context if needed\"
}

Be concise.";

        $result = LLM::json($prompt);

        $isFollowUp = $result['is_follow_up'] ?? false;
        $enhancedInput = $result['enhanced_input'] ?? $input;

        return new LLMResult(
            isFollowUp: $isFollowUp,
            enhancedInput: $enhancedInput,
            confidence: $isFollowUp ? 0.8 : 0.9 // High confidence in LLM decisions
        );
    }
}

class LLMResult
{
    public function __construct(
        private bool $isFollowUp,
        private string $enhancedInput,
        private float $confidence
    ) {}

    public function isFollowUp(): bool { return $this->isFollowUp; }
    public function getEnhancedInput(): string { return $this->enhancedInput; }
    public function getConfidence(): float { return $this->confidence; }
}
```

## 8. Integration with RunAgent.php

```php
// Modified method in RunAgent.php
protected function enhanceTaskWithContext(string $input, string $previousResponse): string
{
    // Initialize conversation context if not exists
    if (!isset($this->conversationContext)) {
        $this->conversationContext = new ConversationContext();
    }

    // Use hybrid recognizer
    $recognizer = new FollowUpRecognizer($this->conversationContext);
    $result = $recognizer->analyze($input, $previousResponse);

    // Optional: Log performance metrics
    if ($this->option('verbose')) {
        $this->info("Recognition: {$result->getMethod()} ({$result->getProcessingTime():.2f}ms)");
    }

    return $result->getEnhancedInput();
}

// Modified chat mode to maintain context
protected function runChatMode(Agent $agent, string $initialTask): void
{
    $this->conversationContext = new ConversationContext();
    // ... existing code ...

    while ($conversationActive) {
        $finalResponse = $agent->run($task);

        // Update conversation context
        $this->conversationContext->addExchange($task, $finalResponse);

        // ... rest of existing code ...

        if (!in_array(strtolower($nextInput), ['exit', 'quit', 'bye', 'q'])) {
            $task = $this->enhanceTaskWithContext($nextInput, $finalResponse);
            $agent->resetForNextTask();
        }
    }
}
```

## Performance Expectations

### Processing Time Targets

- **Pattern Matching**: ~1ms (70-80% of cases)
- **Context Analysis**: ~50ms (15-20% of cases)
- **LLM Fallback**: ~1000ms (5-10% of cases)
- **Overall Average**: ~100ms (75% improvement)

### Memory Usage

- **Conversation Context**: ~10KB per 10 exchanges
- **Pattern Cache**: ~5KB for common patterns
- **Entity Tracking**: ~2KB for 20 entities
- **Total Overhead**: <20KB additional memory

This implementation provides a comprehensive solution that balances performance optimization with accuracy, ensuring the system can handle the majority of follow-up cases efficiently while maintaining fallback mechanisms for complex scenarios.
