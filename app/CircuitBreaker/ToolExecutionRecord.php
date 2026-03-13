<?php

namespace App\CircuitBreaker;

/**
 * Represents a single tool execution record
 */
class ToolExecutionRecord
{
    public function __construct(
        public readonly string $toolName,
        public readonly array $parameters,
        public readonly int $timestamp,
        public readonly bool $success,
        public readonly ?string $result = null,
        public readonly ?string $error = null,
        public readonly float $executionTime = 0.0
    ) {}

    /**
     * Create a record from successful execution
     */
    public static function success(
        string $toolName,
        array $parameters,
        string $result,
        float $executionTime = 0.0
    ): self {
        return new self(
            toolName: $toolName,
            parameters: $parameters,
            timestamp: time(),
            success: true,
            result: $result,
            executionTime: $executionTime
        );
    }

    /**
     * Create a record from failed execution
     */
    public static function failure(
        string $toolName,
        array $parameters,
        string $error,
        float $executionTime = 0.0
    ): self {
        return new self(
            toolName: $toolName,
            parameters: $parameters,
            timestamp: time(),
            success: false,
            error: $error,
            executionTime: $executionTime
        );
    }

    /**
     * Get unique identifier for this tool+parameter combination
     */
    public function getExecutionKey(): string
    {
        return $this->toolName.':'.md5(json_encode($this->parameters));
    }

    /**
     * Get simplified parameter representation
     */
    public function getMainParameter(): string
    {
        return match ($this->toolName) {
            'read_file', 'write_file' => $this->parameters['file_path'] ?? $this->parameters['filename'] ?? '',
            'search_web' => $this->parameters['searchTerm'] ?? $this->parameters['query'] ?? '',
            'browse_website' => $this->parameters['url'] ?? '',
            'run_command' => $this->parameters['command'] ?? '',
            default => json_encode($this->parameters)
        };
    }

    /**
     * Check if this record is older than specified seconds
     */
    public function isOlderThan(int $seconds): bool
    {
        return (time() - $this->timestamp) > $seconds;
    }
}
