<?php

namespace App\Agent\Chat;

/**
 * Minimal context tracking for follow-up recognition.
 * Maintains lightweight conversation context for efficient analysis.
 */
class ContextTracker
{
    private array $conversationHistory = [];

    private array $entityRegistry = [];

    private array $topicKeywords = [];

    private int $maxHistorySize = 10; // Keep last 10 exchanges

    /**
     * Add a conversation exchange
     */
    public function addExchange(string $input, string $response): void
    {
        $exchange = [
            'timestamp' => time(),
            'input' => $input,
            'response' => $response,
            'input_keywords' => $this->extractKeywords($input),
            'response_keywords' => $this->extractKeywords($response),
            'entities' => $this->extractEntities($response),
        ];

        $this->conversationHistory[] = $exchange;

        // Keep history size manageable
        if (count($this->conversationHistory) > $this->maxHistorySize) {
            array_shift($this->conversationHistory);
        }

        // Update entity registry and topic keywords
        $this->updateEntityRegistry($exchange['entities']);
        $this->updateTopicKeywords($exchange['response_keywords']);
    }

    /**
     * Analyze if input is a follow-up based on context
     */
    public function analyze(string $input, string $previousResponse = '', array $conversationHistory = []): ContextResult
    {
        if (empty($this->conversationHistory) && empty($conversationHistory)) {
            return new ContextResult(false, 0.0, '');
        }

        $inputKeywords = $this->extractKeywords($input);
        $confidence = 0.0;
        $relevantContext = '';

        // Get the last exchange for immediate context
        $lastExchange = end($this->conversationHistory) ?: null;

        // Check for topic similarity
        $topicSimilarity = $this->calculateTopicSimilarity($inputKeywords);
        if ($topicSimilarity > 0.3) {
            $confidence += $topicSimilarity * 0.4;
        }

        // Check for entity references
        $entityScore = $this->checkEntityReferences($input);
        if ($entityScore > 0) {
            $confidence += $entityScore * 0.3;
            $relevantContext = $this->getRelevantEntityContext($input);
        }

        // Check for action continuation
        if ($lastExchange) {
            $actionScore = $this->checkActionContinuation($input, $lastExchange);
            if ($actionScore > 0) {
                $confidence += $actionScore * 0.3;
                if (empty($relevantContext)) {
                    $relevantContext = $this->getActionContext($lastExchange);
                }
            }
        }

        // Short inputs with keyword overlap are more likely follow-ups
        if (strlen($input) < 50 && $topicSimilarity > 0.2) {
            $confidence += 0.2;
        }

        return new ContextResult(
            isFollowUp: $confidence > 0.4,
            confidence: min(1.0, $confidence),
            relevantContext: $relevantContext
        );
    }

    /**
     * Calculate topic similarity using keyword overlap
     */
    private function calculateTopicSimilarity(array $inputKeywords): float
    {
        if (empty($this->topicKeywords) || empty($inputKeywords)) {
            return 0.0;
        }

        $intersect = array_intersect($inputKeywords, array_keys($this->topicKeywords));
        $union = array_unique(array_merge($inputKeywords, array_keys($this->topicKeywords)));

        if (empty($union)) {
            return 0.0;
        }

        // Jaccard similarity with frequency weighting
        $score = 0.0;
        foreach ($intersect as $keyword) {
            $frequency = $this->topicKeywords[$keyword] ?? 1;
            $score += min(1.0, $frequency / 3); // Weight by frequency, cap at 1.0
        }

        return $score / count($union);
    }

    /**
     * Check if input references known entities
     */
    private function checkEntityReferences(string $input): float
    {
        $score = 0.0;
        $input_lower = strtolower($input);

        foreach ($this->entityRegistry as $entity => $info) {
            if (strpos($input_lower, strtolower($entity)) !== false) {
                $score += 0.3;

                // Higher score for recent entities
                $age = time() - $info['last_seen'];
                if ($age < 300) { // Within 5 minutes
                    $score += 0.2;
                }
            }
        }

        return min(1.0, $score);
    }

    /**
     * Check for action continuation patterns
     */
    private function checkActionContinuation(string $input, array $lastExchange): float
    {
        $actionPatterns = [
            'test' => ['test', 'try', 'run', 'check', 'verify'],
            'modify' => ['change', 'update', 'edit', 'modify', 'fix'],
            'explain' => ['explain', 'describe', 'how', 'why', 'what'],
            'create' => ['make', 'create', 'generate', 'build'],
            'analyze' => ['analyze', 'examine', 'review', 'look'],
        ];

        $lastResponse = strtolower($lastExchange['response']);
        $currentInput = strtolower($input);

        foreach ($actionPatterns as $action => $keywords) {
            // Check if last response mentioned this action
            foreach ($keywords as $keyword) {
                if (strpos($lastResponse, $keyword) !== false) {
                    // Check if current input continues this action
                    foreach ($keywords as $continueKeyword) {
                        if (strpos($currentInput, $continueKeyword) !== false) {
                            return 0.7;
                        }
                    }

                    // Check for general continuation words
                    $continuationWords = ['it', 'that', 'this', 'now', 'next', 'also'];
                    foreach ($continuationWords as $word) {
                        if (strpos($currentInput, $word) !== false) {
                            return 0.5;
                        }
                    }
                }
            }
        }

        return 0.0;
    }

    /**
     * Extract keywords from text
     */
    private function extractKeywords(string $text): array
    {
        // Remove common stop words and extract meaningful terms
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'can', 'may', 'might'];

        $words = preg_split('/\W+/', strtolower($text));
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && ! in_array($word, $stopWords) && ! is_numeric($word);
        });

        return array_values($keywords);
    }

    /**
     * Extract entities (files, URLs, technologies, etc.)
     */
    private function extractEntities(string $text): array
    {
        $entities = [];

        // File names and extensions
        if (preg_match_all('/\b[a-zA-Z0-9\-_]+\.[a-zA-Z0-9]{2,5}\b/', $text, $matches)) {
            $entities = array_merge($entities, $matches[0]);
        }

        // URLs
        if (preg_match_all('/https?:\/\/[^\s]+/', $text, $matches)) {
            $entities = array_merge($entities, $matches[0]);
        }

        // Technology names (capitalized words, common frameworks)
        if (preg_match_all('/\b[A-Z][a-zA-Z0-9]*(?:\.[A-Z][a-zA-Z0-9]*)*\b/', $text, $matches)) {
            $entities = array_merge($entities, $matches[0]);
        }

        // Common tech terms
        $techTerms = ['php', 'javascript', 'python', 'react', 'vue', 'angular', 'laravel', 'symfony', 'docker', 'kubernetes', 'aws', 'api', 'database', 'mysql', 'postgresql'];
        foreach ($techTerms as $term) {
            if (stripos($text, $term) !== false) {
                $entities[] = $term;
            }
        }

        return array_unique($entities);
    }

    /**
     * Update entity registry with new entities
     */
    private function updateEntityRegistry(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityRegistry[$entity] = [
                'first_seen' => $this->entityRegistry[$entity]['first_seen'] ?? time(),
                'last_seen' => time(),
                'frequency' => ($this->entityRegistry[$entity]['frequency'] ?? 0) + 1,
            ];
        }
    }

    /**
     * Update topic keywords with frequency tracking
     */
    private function updateTopicKeywords(array $keywords): void
    {
        foreach ($keywords as $keyword) {
            $this->topicKeywords[$keyword] = ($this->topicKeywords[$keyword] ?? 0) + 1;
        }

        // Keep only top 50 keywords to prevent memory bloat
        if (count($this->topicKeywords) > 50) {
            arsort($this->topicKeywords);
            $this->topicKeywords = array_slice($this->topicKeywords, 0, 50, true);
        }
    }

    /**
     * Get relevant entity context for enhancement
     */
    private function getRelevantEntityContext(string $input): string
    {
        $input_lower = strtolower($input);
        $relevantEntities = [];

        foreach ($this->entityRegistry as $entity => $info) {
            if (strpos($input_lower, strtolower($entity)) !== false) {
                $relevantEntities[] = $entity;
            }
        }

        return implode(', ', array_slice($relevantEntities, 0, 3));
    }

    /**
     * Get action context from last exchange
     */
    private function getActionContext(array $lastExchange): string
    {
        $response = $lastExchange['response'];

        // Extract the main action or result from the response
        if (preg_match('/(?:created?|generated?|built?|made?)\s+([^.]+)/i', $response, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/(?:analyzed?|processed?|reviewed?)\s+([^.]+)/i', $response, $matches)) {
            return trim($matches[1]);
        }

        // Fallback to first few words
        $words = explode(' ', trim($response));

        return implode(' ', array_slice($words, 0, 5));
    }

    /**
     * Get current context statistics
     */
    public function getContextStats(): array
    {
        return [
            'conversation_exchanges' => count($this->conversationHistory),
            'tracked_entities' => count($this->entityRegistry),
            'topic_keywords' => count($this->topicKeywords),
            'memory_usage' => [
                'history_size' => strlen(serialize($this->conversationHistory)),
                'entity_size' => strlen(serialize($this->entityRegistry)),
                'keyword_size' => strlen(serialize($this->topicKeywords)),
            ],
        ];
    }

    /**
     * Clear old context to prevent memory leaks
     */
    public function clearOldContext(int $maxAge = 3600): void
    {
        $cutoff = time() - $maxAge;

        // Remove old entities
        $this->entityRegistry = array_filter($this->entityRegistry, function ($info) use ($cutoff) {
            return $info['last_seen'] > $cutoff;
        });

        // Reduce topic keywords
        if (count($this->topicKeywords) > 30) {
            arsort($this->topicKeywords);
            $this->topicKeywords = array_slice($this->topicKeywords, 0, 30, true);
        }
    }
}

/**
 * Result of context analysis
 */
class ContextResult
{
    public function __construct(
        private bool $isFollowUp,
        private float $confidence,
        private string $relevantContext
    ) {}

    public function isFollowUp(): bool
    {
        return $this->isFollowUp;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getRelevantContext(): string
    {
        return $this->relevantContext;
    }
}
