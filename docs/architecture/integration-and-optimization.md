# Integration Plan & Performance Optimization Strategies

## RunAgent.php Integration Plan

### Phase 1: Minimal Integration (1-2 days)

#### Step 1: Create Core Directory Structure

```bash
mkdir -p app/Agent/Chat
mkdir -p app/Agent/Chat/Metrics
```

#### Step 2: Implement Base Classes

Create the following files in priority order:

1. **RecognitionResult.php** (Value objects)
2. **PatternRules.php** (Pattern definitions)
3. **PatternMatcher.php** (Fast path)
4. **ConversationContext.php** (Context management)
5. **FollowUpRecognizer.php** (Main orchestrator)

#### Step 3: Modify RunAgent.php

```php
// Add property for conversation context
private ?ConversationContext $conversationContext = null;

// Modify enhanceTaskWithContext method
protected function enhanceTaskWithContext(string $input, string $previousResponse): string
{
    // Initialize context if first time
    if (!$this->conversationContext) {
        $this->conversationContext = new ConversationContext();
    }

    // Use hybrid recognizer (start with pattern matching only)
    $recognizer = new FollowUpRecognizer($this->conversationContext);
    $result = $recognizer->analyze($input, $previousResponse);

    // Track metrics if verbose mode
    if ($this->option('verbose')) {
        $this->info("Recognition: {$result->getMethod()} ({$result->getProcessingTime():.2f}ms)");
    }

    return $result->getEnhancedInput();
}

// Update runChatMode to maintain context
protected function runChatMode(Agent $agent, string $initialTask): void
{
    $this->conversationContext = new ConversationContext();
    // ... existing code ...

    while ($conversationActive) {
        $finalResponse = $agent->run($task);

        // Update conversation context
        $this->conversationContext->addExchange($task, $finalResponse);

        // ... rest of existing code ...
    }
}
```

### Phase 2: Full Implementation (2-3 days)

#### Step 4: Add Context Analysis

1. Implement **ContextAnalyzer.php**
2. Integrate with FollowUpRecognizer
3. Test medium-complexity cases

#### Step 5: Add LLM Fallback

1. Implement **LLMClassifier.php**
2. Optimize existing LLM prompt
3. Add caching layer

#### Step 6: Add Performance Monitoring

1. Implement **RecognitionMetrics.php**
2. Add performance tracking
3. Implement alerting thresholds

### Phase 3: Optimization (1-2 days)

#### Step 7: Performance Tuning

1. Profile each component
2. Optimize pattern matching
3. Implement caching strategies

#### Step 8: Testing & Validation

1. A/B test against current implementation
2. Validate accuracy metrics
3. Performance regression testing

## Performance Optimization Strategies

### 1. Pattern Matching Optimization

#### Regex Performance Optimization

```php
class OptimizedPatternMatcher extends PatternMatcher
{
    private array $compiledPatterns = [];
    private array $patternCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->precompilePatterns();
    }

    private function precompilePatterns(): void
    {
        // Pre-compile and cache regex patterns
        foreach ($this->rules->getAllPatterns() as $pattern) {
            $this->compiledPatterns[$pattern['type']] = [
                'regex' => $pattern['regex'],
                'compiled' => preg_quote($pattern['regex'], '/'),
                'confidence' => $pattern['confidence']
            ];
        }
    }

    public function match(string $input, string $previousResponse): PatternResult
    {
        $inputHash = md5($input);

        // Check cache first
        if (isset($this->patternCache[$inputHash])) {
            return $this->patternCache[$inputHash];
        }

        $result = parent::match($input, $previousResponse);

        // Cache successful matches
        if ($result->isMatch()) {
            $this->patternCache[$inputHash] = $result;

            // Limit cache size
            if (count($this->patternCache) > 1000) {
                array_shift($this->patternCache);
            }
        }

        return $result;
    }
}
```

#### Fast Path Shortcuts

```php
class FastPatternMatcher
{
    private array $quickMatches = [
        // Exact matches - no regex needed
        'yes' => ['type' => 'continuation_command', 'confidence' => 0.95],
        'ok' => ['type' => 'continuation_command', 'confidence' => 0.95],
        'sure' => ['type' => 'continuation_command', 'confidence' => 0.95],
        'no' => ['type' => 'continuation_command', 'confidence' => 0.95],
        'another one' => ['type' => 'continuation_command', 'confidence' => 0.95],
        'more' => ['type' => 'continuation_command', 'confidence' => 0.85],
        'fix it' => ['type' => 'pronoun_reference', 'confidence' => 0.95],
        'do it' => ['type' => 'pronoun_reference', 'confidence' => 0.95],
        'show it' => ['type' => 'pronoun_reference', 'confidence' => 0.95],
    ];

    public function quickMatch(string $input): ?PatternResult
    {
        $normalized = trim(strtolower($input));

        if (isset($this->quickMatches[$normalized])) {
            $match = $this->quickMatches[$normalized];
            return new PatternResult(
                isMatch: true,
                enhancedInput: $this->enhanceQuickMatch($input, $match),
                confidence: $match['confidence'],
                matchType: $match['type']
            );
        }

        return null;
    }
}
```

### 2. Context Analysis Optimization

#### Lazy Loading Strategy

```php
class OptimizedContextAnalyzer extends ContextAnalyzer
{
    private ?array $cachedEntities = null;
    private ?array $cachedTopicTokens = null;

    protected function analyzeEntityReferences(string $input): float
    {
        // Lazy load entities only when needed
        if ($this->cachedEntities === null) {
            $this->cachedEntities = $this->context->getTrackedEntities();
        }

        return parent::analyzeEntityReferences($input);
    }

    protected function analyzeTopicSimilarity(string $input, string $previousResponse): float
    {
        // Cache topic tokens to avoid re-tokenization
        if ($this->cachedTopicTokens === null) {
            $this->cachedTopicTokens = $this->tokenize($previousResponse);
        }

        $inputTokens = $this->tokenize($input);
        $intersection = array_intersect($inputTokens, $this->cachedTopicTokens);
        $union = array_unique(array_merge($inputTokens, $this->cachedTopicTokens));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }
}
```

#### Context Window Optimization

```php
class OptimizedConversationContext extends ConversationContext
{
    private array $entityIndex = [];
    private array $topicIndex = [];
    private int $compressionThreshold = 8;

    public function addExchange(string $input, string $response): void
    {
        parent::addExchange($input, $response);

        // Update indexes for fast lookups
        $this->updateEntityIndex($input, $response);
        $this->updateTopicIndex($input, $response);

        // Compress if needed
        if (count($this->exchanges) > $this->compressionThreshold) {
            $this->compressOldExchanges();
        }
    }

    private function compressOldExchanges(): void
    {
        // Keep last 5 exchanges, compress the rest
        $toCompress = array_slice($this->exchanges, 0, -5);
        $toKeep = array_slice($this->exchanges, -5);

        // Create compressed summary
        $compressed = $this->createCompressedSummary($toCompress);

        // Update exchanges array
        $this->exchanges = array_merge([$compressed], $toKeep);
    }

    private function createCompressedSummary(array $exchanges): array
    {
        $entities = [];
        $topics = [];
        $actions = [];

        foreach ($exchanges as $exchange) {
            $entities = array_merge($entities, $exchange['entities'] ?? []);
            // Extract key topics and actions
        }

        return [
            'input' => '[Compressed Summary]',
            'response' => 'Previous conversation covered: ' . $this->summarizeTopics($topics),
            'timestamp' => $exchanges[0]['timestamp'] ?? time(),
            'entities' => array_unique($entities, SORT_REGULAR),
            'compressed' => true,
            'original_count' => count($exchanges)
        ];
    }
}
```

### 3. LLM Optimization

#### Prompt Optimization

```php
class OptimizedLLMClassifier extends LLMClassifier
{
    private array $responseCache = [];
    private string $optimizedPrompt = 'Classify input as follow-up (Y/N) and enhance if needed.

Previous: {previous}
Input: {input}

JSON: {"follow_up": bool, "enhanced": "text"}';

    public function classify(
        string $input,
        string $previousResponse,
        ConversationContext $context
    ): LLMResult {
        // Check cache first
        $cacheKey = md5($input . substr($previousResponse, 0, 100));
        if (isset($this->responseCache[$cacheKey])) {
            return $this->responseCache[$cacheKey];
        }

        // Use optimized shorter prompt
        $prompt = str_replace(
            ['{previous}', '{input}'],
            [substr($previousResponse, 0, 200), $input],
            $this->optimizedPrompt
        );

        $result = LLM::json($prompt);

        $llmResult = new LLMResult(
            isFollowUp: $result['follow_up'] ?? false,
            enhancedInput: $result['enhanced'] ?? $input,
            confidence: 0.8
        );

        // Cache result
        $this->responseCache[$cacheKey] = $llmResult;

        // Limit cache size
        if (count($this->responseCache) > 100) {
            array_shift($this->responseCache);
        }

        return $llmResult;
    }
}
```

#### Model Selection Strategy

```php
class AdaptiveLLMClassifier extends LLMClassifier
{
    public function classify(
        string $input,
        string $previousResponse,
        ConversationContext $context
    ): LLMResult {
        // Use faster, cheaper model for simple cases
        if (strlen($input) < 20 && $this->isSimpleCase($input)) {
            return $this->classifyWithFastModel($input, $previousResponse);
        }

        // Use premium model for complex cases
        return $this->classifyWithPremiumModel($input, $previousResponse, $context);
    }

    private function isSimpleCase(string $input): bool
    {
        $simplePatterns = ['what', 'how', 'why', 'when', 'where'];
        $words = explode(' ', strtolower($input));

        return count($words) <= 5 &&
               count(array_intersect($words, $simplePatterns)) > 0;
    }
}
```

### 4. Caching Strategies

#### Multi-Level Caching

```php
class CachedFollowUpRecognizer extends FollowUpRecognizer
{
    private array $l1Cache = []; // Pattern cache
    private array $l2Cache = []; // Context cache
    private array $l3Cache = []; // LLM cache

    public function analyze(string $input, string $previousResponse): RecognitionResult
    {
        $inputHash = $this->generateInputHash($input, $previousResponse);

        // Check L1 cache (pattern matches)
        if (isset($this->l1Cache[$inputHash])) {
            return $this->l1Cache[$inputHash];
        }

        // Check L2 cache (context analysis)
        if (isset($this->l2Cache[$inputHash])) {
            return $this->l2Cache[$inputHash];
        }

        // Check L3 cache (LLM results)
        if (isset($this->l3Cache[$inputHash])) {
            return $this->l3Cache[$inputHash];
        }

        // Perform analysis
        $result = parent::analyze($input, $previousResponse);

        // Cache based on method used
        switch ($result->getMethod()) {
            case 'pattern':
                $this->l1Cache[$inputHash] = $result;
                break;
            case 'context':
                $this->l2Cache[$inputHash] = $result;
                break;
            case 'llm':
                $this->l3Cache[$inputHash] = $result;
                break;
        }

        return $result;
    }
}
```

### 5. Memory Optimization

#### Memory-Efficient Data Structures

```php
class MemoryOptimizedContext
{
    private SplObjectStorage $exchanges;
    private SplFixedArray $entityBuffer;
    private WeakMap $responseIndex;

    public function __construct(int $maxExchanges = 10)
    {
        $this->exchanges = new SplObjectStorage();
        $this->entityBuffer = new SplFixedArray($maxExchanges);
        $this->responseIndex = new WeakMap();
    }

    public function addExchange(string $input, string $response): void
    {
        $exchange = new class($input, $response) {
            public function __construct(
                public readonly string $input,
                public readonly string $response,
                public readonly int $timestamp = time()
            ) {}
        };

        $this->exchanges->attach($exchange);

        // Use fixed-size buffer for entities
        $this->rotateEntityBuffer($this->extractEntities($input . ' ' . $response));

        // Clean up old references
        if ($this->exchanges->count() > 10) {
            $this->exchanges->rewind();
            $this->exchanges->detach($this->exchanges->current());
        }
    }

    private function rotateEntityBuffer(array $entities): void
    {
        // Shift buffer and add new entities
        for ($i = $this->entityBuffer->getSize() - 1; $i > 0; $i--) {
            $this->entityBuffer[$i] = $this->entityBuffer[$i - 1];
        }
        $this->entityBuffer[0] = $entities;
    }
}
```

## Performance Benchmarking

### Benchmark Test Suite

```php
class FollowUpRecognitionBenchmark
{
    private array $testCases = [
        // Pattern matching cases (should be <1ms)
        ['input' => 'fix it', 'expected_method' => 'pattern'],
        ['input' => 'yes', 'expected_method' => 'pattern'],
        ['input' => 'another one', 'expected_method' => 'pattern'],

        // Context analysis cases (should be <50ms)
        ['input' => 'improve the UserService', 'expected_method' => 'context'],
        ['input' => 'what about performance', 'expected_method' => 'context'],

        // LLM fallback cases (should be <1000ms)
        ['input' => 'elaborate on the implications', 'expected_method' => 'llm'],
        ['input' => 'how does this relate to scalability', 'expected_method' => 'llm'],
    ];

    public function runBenchmark(): array
    {
        $results = [];
        $recognizer = new FollowUpRecognizer(new ConversationContext());

        foreach ($this->testCases as $case) {
            $startTime = microtime(true);
            $result = $recognizer->analyze($case['input'], 'Previous response...');
            $endTime = microtime(true);

            $results[] = [
                'input' => $case['input'],
                'expected_method' => $case['expected_method'],
                'actual_method' => $result->getMethod(),
                'processing_time' => ($endTime - $startTime) * 1000, // ms
                'success' => $case['expected_method'] === $result->getMethod(),
            ];
        }

        return $this->analyzeResults($results);
    }

    private function analyzeResults(array $results): array
    {
        $totalTime = array_sum(array_column($results, 'processing_time'));
        $averageTime = $totalTime / count($results);
        $accuracy = array_sum(array_column($results, 'success')) / count($results);

        $methodStats = [];
        foreach ($results as $result) {
            $method = $result['actual_method'];
            if (!isset($methodStats[$method])) {
                $methodStats[$method] = ['count' => 0, 'total_time' => 0];
            }
            $methodStats[$method]['count']++;
            $methodStats[$method]['total_time'] += $result['processing_time'];
        }

        foreach ($methodStats as $method => &$stats) {
            $stats['average_time'] = $stats['total_time'] / $stats['count'];
            $stats['percentage'] = ($stats['count'] / count($results)) * 100;
        }

        return [
            'summary' => [
                'total_tests' => count($results),
                'total_time' => $totalTime,
                'average_time' => $averageTime,
                'accuracy' => $accuracy * 100,
            ],
            'method_breakdown' => $methodStats,
            'detailed_results' => $results,
        ];
    }
}
```

## Monitoring & Alerting

### Performance Monitoring

```php
class PerformanceMonitor
{
    private array $metrics = [];
    private float $patternThreshold = 2.0; // ms
    private float $contextThreshold = 100.0; // ms
    private float $llmThreshold = 2000.0; // ms

    public function recordMetric(RecognitionResult $result): void
    {
        $method = $result->getMethod();
        $time = $result->getProcessingTime() * 1000; // Convert to ms

        $this->metrics[] = [
            'method' => $method,
            'time' => $time,
            'timestamp' => time(),
            'exceeded_threshold' => $this->exceededThreshold($method, $time)
        ];

        // Check for performance issues
        if ($this->exceededThreshold($method, $time)) {
            $this->alertPerformanceIssue($method, $time);
        }

        // Cleanup old metrics (keep last 1000)
        if (count($this->metrics) > 1000) {
            array_shift($this->metrics);
        }
    }

    private function exceededThreshold(string $method, float $time): bool
    {
        return match($method) {
            'pattern' => $time > $this->patternThreshold,
            'context' => $time > $this->contextThreshold,
            'llm' => $time > $this->llmThreshold,
            default => false
        };
    }

    public function getPerformanceReport(): array
    {
        $methodStats = [];
        $recentMetrics = array_slice($this->metrics, -100); // Last 100

        foreach ($recentMetrics as $metric) {
            $method = $metric['method'];
            if (!isset($methodStats[$method])) {
                $methodStats[$method] = [
                    'count' => 0,
                    'total_time' => 0,
                    'max_time' => 0,
                    'threshold_violations' => 0
                ];
            }

            $methodStats[$method]['count']++;
            $methodStats[$method]['total_time'] += $metric['time'];
            $methodStats[$method]['max_time'] = max(
                $methodStats[$method]['max_time'],
                $metric['time']
            );

            if ($metric['exceeded_threshold']) {
                $methodStats[$method]['threshold_violations']++;
            }
        }

        // Calculate averages
        foreach ($methodStats as &$stats) {
            $stats['avg_time'] = $stats['total_time'] / $stats['count'];
            $stats['violation_rate'] =
                ($stats['threshold_violations'] / $stats['count']) * 100;
        }

        return [
            'summary' => [
                'total_requests' => count($recentMetrics),
                'avg_time' => array_sum(array_column($recentMetrics, 'time')) /
                             count($recentMetrics),
                'method_distribution' => array_map(
                    fn($stats) => $stats['count'],
                    $methodStats
                )
            ],
            'method_performance' => $methodStats,
            'recommendations' => $this->generateRecommendations($methodStats)
        ];
    }

    private function generateRecommendations(array $methodStats): array
    {
        $recommendations = [];

        foreach ($methodStats as $method => $stats) {
            if ($stats['violation_rate'] > 10) {
                $recommendations[] =
                    "High threshold violations for {$method} method ({$stats['violation_rate']}%). Consider optimization.";
            }

            if ($method === 'llm' && $stats['count'] > 20) {
                $recommendations[] =
                    "High LLM usage ({$stats['count']} calls). Consider improving pattern/context detection.";
            }
        }

        return $recommendations;
    }
}
```

This comprehensive integration and optimization plan provides a roadmap for implementing the hybrid follow-up recognition system with maximum performance benefits while maintaining code quality and system reliability.
