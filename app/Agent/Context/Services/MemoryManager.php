<?php

namespace App\Agent\Context\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MemoryManager
{
    protected array $config;

    protected string $workingMemoryPrefix = 'agent_context_working_';

    protected string $compressedMemoryPrefix = 'agent_context_compressed_';

    protected string $archiveMemoryPrefix = 'agent_context_archive_';

    protected string $metadataPrefix = 'agent_context_metadata_';

    public function __construct()
    {
        $this->config = config('app.context_compression.memory', []);
    }

    /**
     * Store compressed context in appropriate memory tier
     */
    public function storeCompressedContext(array $context): string
    {
        $contextId = $this->generateContextId($context);

        // Store in compressed memory tier with TTL
        $ttl = $this->config['compressed_ttl'] ?? 86400; // 24 hours

        Cache::put(
            $this->compressedMemoryPrefix.$contextId,
            $context,
            $ttl
        );

        // Store metadata for indexing and search
        $this->storeContextMetadata($contextId, $context);

        // Archive older contexts if needed
        $this->archiveOldContexts();

        return $contextId;
    }

    /**
     * Store working context (current conversation)
     */
    public function storeWorkingContext(string $sessionId, array $steps): void
    {
        $ttl = $this->config['working_ttl'] ?? 0; // Current session only

        $cacheKey = $this->workingMemoryPrefix.$sessionId;

        if ($ttl > 0) {
            Cache::put($cacheKey, $steps, $ttl);
        } else {
            // Store for current session only (no TTL)
            Cache::forever($cacheKey, $steps);
        }
    }

    /**
     * Retrieve context from any memory tier
     */
    public function retrieveContext(string $contextId, string $tier = 'compressed'): ?array
    {
        $prefix = match ($tier) {
            'working' => $this->workingMemoryPrefix,
            'compressed' => $this->compressedMemoryPrefix,
            'archive' => $this->archiveMemoryPrefix,
            default => $this->compressedMemoryPrefix
        };

        $context = Cache::get($prefix.$contextId);

        if (! $context && $tier === 'compressed') {
            // Try archive if not found in compressed
            $context = Cache::get($this->archiveMemoryPrefix.$contextId);
        }

        return $context;
    }

    /**
     * Search contexts by metadata
     */
    public function searchContexts(array $criteria, int $limit = 10): array
    {
        $metadataKeys = Cache::get($this->metadataPrefix.'index', []);
        $results = [];

        foreach ($metadataKeys as $contextId => $metadata) {
            if ($this->matchesCriteria($metadata, $criteria)) {
                $context = $this->retrieveContext($contextId);
                if ($context) {
                    $results[] = [
                        'id' => $contextId,
                        'context' => $context,
                        'metadata' => $metadata,
                        'relevance_score' => $this->calculateRelevanceScore($metadata, $criteria),
                    ];
                }

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        // Sort by relevance score
        usort($results, fn ($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);

        return $results;
    }

    /**
     * Get memory usage statistics
     */
    public function getMemoryStats(): array
    {
        $stats = [
            'working_contexts' => 0,
            'compressed_contexts' => 0,
            'archived_contexts' => 0,
            'total_size_bytes' => 0,
            'oldest_context' => null,
            'newest_context' => null,
        ];

        // This is a simplified implementation
        // In production, you'd want more sophisticated tracking
        $metadataIndex = Cache::get($this->metadataPrefix.'index', []);
        $stats['compressed_contexts'] = count($metadataIndex);

        $timestamps = array_column($metadataIndex, 'timestamp');
        if (! empty($timestamps)) {
            $stats['oldest_context'] = min($timestamps);
            $stats['newest_context'] = max($timestamps);
        }

        return $stats;
    }

    /**
     * Clean up expired contexts
     */
    public function cleanup(): array
    {
        $cleaned = [
            'working' => 0,
            'compressed' => 0,
            'archived' => 0,
        ];

        // Clean up expired compressed contexts
        $metadataIndex = Cache::get($this->metadataPrefix.'index', []);
        $now = time();
        $compressedTtl = $this->config['compressed_ttl'] ?? 86400;

        foreach ($metadataIndex as $contextId => $metadata) {
            $age = $now - ($metadata['timestamp'] ?? 0);

            if ($age > $compressedTtl) {
                // Move to archive or delete
                $context = Cache::get($this->compressedMemoryPrefix.$contextId);
                if ($context) {
                    $archiveTtl = $this->config['archive_ttl'] ?? 2592000; // 30 days

                    if ($age < $archiveTtl) {
                        // Move to archive
                        Cache::put(
                            $this->archiveMemoryPrefix.$contextId,
                            $this->compressContext($context),
                            $archiveTtl - $age
                        );
                    }

                    Cache::forget($this->compressedMemoryPrefix.$contextId);
                    $cleaned['compressed']++;
                }

                unset($metadataIndex[$contextId]);
            }
        }

        // Update metadata index
        Cache::put($this->metadataPrefix.'index', $metadataIndex);

        return $cleaned;
    }

    /**
     * Export contexts for backup
     */
    public function exportContexts(?string $sessionId = null): array
    {
        $export = [
            'timestamp' => time(),
            'session_id' => $sessionId,
            'contexts' => [],
        ];

        if ($sessionId) {
            // Export specific session
            $workingContext = Cache::get($this->workingMemoryPrefix.$sessionId);
            if ($workingContext) {
                $export['contexts']['working'] = $workingContext;
            }
        } else {
            // Export all contexts
            $metadataIndex = Cache::get($this->metadataPrefix.'index', []);
            foreach ($metadataIndex as $contextId => $metadata) {
                $context = $this->retrieveContext($contextId);
                if ($context) {
                    $export['contexts'][$contextId] = $context;
                }
            }
        }

        return $export;
    }

    /**
     * Import contexts from backup
     */
    public function importContexts(array $data): bool
    {
        try {
            foreach ($data['contexts'] ?? [] as $contextId => $context) {
                if ($contextId === 'working' && isset($data['session_id'])) {
                    $this->storeWorkingContext($data['session_id'], $context);
                } else {
                    $this->storeCompressedContext($context);
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate unique context ID
     */
    protected function generateContextId(array $context): string
    {
        $metadata = $context['metadata'] ?? [];
        $timestamp = $metadata['compression_timestamp'] ?? time();
        $hash = substr(md5(serialize($context)), 0, 8);

        return "ctx_{$timestamp}_{$hash}";
    }

    /**
     * Store context metadata for indexing
     */
    protected function storeContextMetadata(string $contextId, array $context): void
    {
        $metadata = $context['metadata'] ?? [];

        $indexData = [
            'timestamp' => time(),
            'type' => $context['type'] ?? 'compressed_context',
            'file_operations' => $metadata['file_operations'] ?? [],
            'critical_facts' => $metadata['critical_facts'] ?? [],
            'user_preferences' => $metadata['user_preferences'] ?? [],
            'compression_type' => $metadata['compression_type'] ?? 'unknown',
            'size_estimate' => strlen(serialize($context)),
        ];

        // Get existing metadata index
        $metadataIndex = Cache::get($this->metadataPrefix.'index', []);
        $metadataIndex[$contextId] = $indexData;

        // Store updated index
        $ttl = $this->config['metadata_ttl'] ?? 7776000; // 90 days
        Cache::put($this->metadataPrefix.'index', $metadataIndex, $ttl);
    }

    /**
     * Archive old contexts to longer-term storage
     */
    protected function archiveOldContexts(): void
    {
        $metadataIndex = Cache::get($this->metadataPrefix.'index', []);
        $maxCompressedContexts = $this->config['max_compressed_contexts'] ?? 100;

        if (count($metadataIndex) <= $maxCompressedContexts) {
            return;
        }

        // Sort by timestamp, oldest first
        uasort($metadataIndex, fn ($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        $contextsToArchive = array_slice($metadataIndex, 0, count($metadataIndex) - $maxCompressedContexts, true);

        foreach (array_keys($contextsToArchive) as $contextId) {
            $context = Cache::get($this->compressedMemoryPrefix.$contextId);
            if ($context) {
                // Compress further and move to archive
                $archiveTtl = $this->config['archive_ttl'] ?? 2592000; // 30 days
                Cache::put(
                    $this->archiveMemoryPrefix.$contextId,
                    $this->compressContext($context),
                    $archiveTtl
                );

                Cache::forget($this->compressedMemoryPrefix.$contextId);
            }
        }
    }

    /**
     * Further compress context for archival
     */
    protected function compressContext(array $context): array
    {
        // Remove verbose metadata and compress content
        $archived = [
            'type' => $context['type'] ?? 'compressed_context',
            'content' => $context['content'] ?? '',
            'archived_at' => time(),
            'essential_metadata' => [
                'file_operations' => $context['metadata']['file_operations'] ?? [],
                'critical_facts' => array_slice($context['metadata']['critical_facts'] ?? [], 0, 3),
                'compression_version' => $context['metadata']['compression_version'] ?? '1.0',
            ],
        ];

        return $archived;
    }

    /**
     * Check if metadata matches search criteria
     */
    protected function matchesCriteria(array $metadata, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            if (! isset($metadata[$key])) {
                continue;
            }

            if (is_array($metadata[$key])) {
                if (is_array($value)) {
                    // Check for intersection
                    if (empty(array_intersect($metadata[$key], $value))) {
                        return false;
                    }
                } else {
                    // Check if value is in array
                    if (! in_array($value, $metadata[$key])) {
                        return false;
                    }
                }
            } else {
                if ($metadata[$key] !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Calculate relevance score for search results
     */
    protected function calculateRelevanceScore(array $metadata, array $criteria): float
    {
        $score = 0.0;
        $maxScore = 0.0;

        foreach ($criteria as $key => $value) {
            $maxScore += 1.0;

            if (! isset($metadata[$key])) {
                continue;
            }

            if (is_array($metadata[$key]) && is_array($value)) {
                $intersection = array_intersect($metadata[$key], $value);
                $score += count($intersection) / max(count($value), 1);
            } elseif ($metadata[$key] === $value) {
                $score += 1.0;
            }
        }

        // Add recency bonus
        $age = time() - ($metadata['timestamp'] ?? 0);
        $recencyBonus = max(0, 1 - ($age / 86400)); // Decay over 24 hours
        $score += $recencyBonus * 0.1;

        return $maxScore > 0 ? $score / $maxScore : 0.0;
    }
}
