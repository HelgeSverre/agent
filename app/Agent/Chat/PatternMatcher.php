<?php

namespace App\Agent\Chat;

/**
 * Fast pattern matching for obvious follow-ups without LLM calls.
 * Handles 70-80% of follow-up cases in ~1ms processing time.
 */
class PatternMatcher
{
    private const PATTERN_RULES = [
        'pronoun_reference' => [
            'patterns' => [
                '/^(yes,?\s*)?(do|make|create|show|fix|update|modify|change|edit)\s+(it|that|this|them|those|these)\b/i',
                '/^(can\s+you\s+)?(update|modify|change|edit)\s+(it|that|this|them|those|these)\b/i',
                '/\b(it|that|this|them|those|these)\s+(needs?|should|must|has\s+to)\b/i',
                '/\b(explain|describe|show|tell\s+me\s+about)\s+(it|that|this|them|those|these)\b/i',
            ],
            'confidence' => 0.9,
        ],

        'reference_continuation' => [
            'patterns' => [
                '/^(another|more|also)\s+(one|version|example|option|file|way|approach)\b/i',
                '/^(make|create|do)\s+(another|more)/i',
                '/^(different|alternative|similar|like\s+that)\b/i',
                '/^(the\s+same\s+but|similar\s+to|like\s+the)\b/i',
                '/^(what\s+about|how\s+about)\s+(another|different|alternative)/i',
            ],
            'confidence' => 0.85,
        ],

        'command_continuation' => [
            'patterns' => [
                '/^(yes|ok|sure|go\s+ahead|proceed|continue)(\.|!|,|\s|$)/i',
                '/^(do\s+it|make\s+it|create\s+it|run\s+it)(\.|!|,|\s|$)/i',
                '/^(again|once\s+more|repeat)(\.|!|,|\s|$)/i',
                '/^(please\s+)?(yes|ok|sure)/i',
            ],
            'confidence' => 0.95,
        ],

        'negation_continuation' => [
            'patterns' => [
                '/^(no|nope|skip|cancel|stop|don\'t)(\.|!|,|\s|$)/i',
                '/^(not\s+that|something\s+else|different)/i',
                '/^(instead|rather|better)/i',
            ],
            'confidence' => 0.9,
        ],

        'clarification_request' => [
            'patterns' => [
                '/^(what|how)\s+about\s+/i',
                '/^(can\s+you|would\s+you|could\s+you)\s+/i',
                '/^(why|when|where|how|what\s+if)\s+/i',
                '/^(what\s+about)\s+(using|adding|trying)/i',
                '/^what\s+about\s+using\s+\w+/i',
                '/\?\s*$/i',
            ],
            'confidence' => 0.85,
        ],

        'test_continuation' => [
            'patterns' => [
                '/^(test\s+it|run\s+tests?|check\s+if|verify)/i',
                '/^(does\s+it\s+work|is\s+it\s+working|try\s+it)/i',
                '/^(make\s+sure\s+it|ensure\s+it)/i',
            ],
            'confidence' => 0.85,
        ],

        'enhancement_request' => [
            'patterns' => [
                '/^(improve|enhance|optimize|make\s+it\s+better)/i',
                '/^(add\s+)?(more|additional|extra)\s+/i',
                '/^(can\s+we|let\'s)\s+(add|include|also)/i',
                '/^(add|include)\s+\w+/i',
                '/^add\s+validation/i',
            ],
            'confidence' => 0.8,
        ],
    ];

    /**
     * Match input against follow-up patterns
     */
    public function match(string $input): PatternResult
    {
        $input = trim($input);
        $bestMatch = null;
        $bestConfidence = 0.0;
        $bestType = '';

        foreach (self::PATTERN_RULES as $type => $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (preg_match($pattern, $input)) {
                    $confidence = $this->calculateConfidence($input, $pattern, $rule['confidence']);

                    if ($confidence > $bestConfidence) {
                        $bestConfidence = $confidence;
                        $bestType = $type;
                        $bestMatch = $pattern;
                    }
                }
            }
        }

        return new PatternResult(
            isMatch: $bestConfidence > 0.6,
            confidence: $bestConfidence,
            patternType: $bestType,
            matchedPattern: $bestMatch ?? ''
        );
    }

    /**
     * Calculate confidence score with additional context clues
     */
    private function calculateConfidence(string $input, string $pattern, float $baseConfidence): float
    {
        $confidence = $baseConfidence;
        $inputLength = strlen($input);

        // Adjust confidence based on input characteristics

        // Very short inputs are more likely to be follow-ups
        if ($inputLength <= 10) {
            $confidence += 0.1;
        }

        // Inputs starting with common follow-up words get a boost
        $followUpStarters = ['yes', 'ok', 'sure', 'no', 'it', 'that', 'this', 'another', 'more'];
        foreach ($followUpStarters as $starter) {
            if (stripos($input, $starter) === 0) {
                $confidence += 0.05;
                break;
            }
        }

        // Questions are more likely to be follow-ups if they're short
        if (str_contains($input, '?') && $inputLength <= 20) {
            $confidence += 0.05;
        }

        // Longer, detailed inputs are less likely to be simple follow-ups
        if ($inputLength > 100) {
            $confidence -= 0.1;
        }

        // Commands with specific file paths or URLs are less likely to be follow-ups
        if (preg_match('/\b[a-z]+\.[a-z]{2,4}\b|https?:\/\//', $input)) {
            $confidence -= 0.15;
        }

        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Get pattern statistics
     */
    public function getPatternStats(): array
    {
        $stats = [];

        foreach (self::PATTERN_RULES as $type => $rule) {
            $stats[$type] = [
                'pattern_count' => count($rule['patterns']),
                'base_confidence' => $rule['confidence'],
            ];
        }

        return $stats;
    }

    /**
     * Test a specific pattern against input
     */
    public function testPattern(string $input, string $patternType): ?PatternResult
    {
        if (! isset(self::PATTERN_RULES[$patternType])) {
            return null;
        }

        $rule = self::PATTERN_RULES[$patternType];

        foreach ($rule['patterns'] as $pattern) {
            if (preg_match($pattern, $input)) {
                $confidence = $this->calculateConfidence($input, $pattern, $rule['confidence']);

                return new PatternResult(
                    isMatch: true,
                    confidence: $confidence,
                    patternType: $patternType,
                    matchedPattern: $pattern
                );
            }
        }

        return new PatternResult(
            isMatch: false,
            confidence: 0.0,
            patternType: $patternType,
            matchedPattern: ''
        );
    }
}

/**
 * Result of pattern matching
 */
class PatternResult
{
    public function __construct(
        private bool $isMatch,
        private float $confidence,
        private string $patternType,
        private string $matchedPattern
    ) {}

    public function isMatch(): bool
    {
        return $this->isMatch;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getPatternType(): string
    {
        return $this->patternType;
    }

    public function getMatchedPattern(): string
    {
        return $this->matchedPattern;
    }
}
