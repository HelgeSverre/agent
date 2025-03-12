<?php

namespace App\Agent;

class ErrorRecovery
{
    protected int $maxRetries = 3;

    protected array $recoverableErrors = [
        'json_decode_error' => 'The model provided invalid JSON. Let me fix that and try again.',
        'invalid_tool_arguments' => 'I provided incorrect arguments to a tool. Let me fix that and try again.',
        'tool_execution_error' => 'There was an error executing the tool. Let me try a different approach.',
    ];

    public function __construct(protected ?Hooks $hooks = null)
    {
    }

    public function handleError(string $errorType, string $errorDetails, callable $retryFunction, int $currentRetry = 0): mixed
    {
        if ($currentRetry >= $this->maxRetries) {
            return [
                'error' => true,
                'message' => "Maximum retries reached for error: {$errorType}",
                'details' => $errorDetails,
            ];
        }

        $recovery_message = $this->recoverableErrors[$errorType] ?? "An error occurred: {$errorDetails}";
        $this->hooks?->trigger('error_recovery', $errorType, $errorDetails, $recovery_message, $currentRetry + 1);

        // Add a hint to the model about what went wrong
        $result = $retryFunction($recovery_message, $currentRetry + 1);

        // If still errors, retry recursively
        if (isset($result['error'])) {
            return $this->handleError(
                $result['error_type'] ?? $errorType,
                $result['error_details'] ?? $errorDetails,
                $retryFunction,
                $currentRetry + 1
            );
        }

        return $result;
    }

    public function validateToolResponse(array $response, array $toolSchema): array
    {
        $errors = [];

        foreach ($toolSchema['required'] as $requiredParam) {
            if (! isset($response[$requiredParam])) {
                $errors[] = "Missing required parameter: {$requiredParam}";
            }
        }

        foreach ($response as $key => $value) {
            if (! isset($toolSchema['properties'][$key])) {
                $errors[] = "Unknown parameter: {$key}";

                continue;
            }

            $expectedType = $toolSchema['properties'][$key]['type'];
            $actualType = $this->getValueType($value);

            if ($expectedType !== $actualType) {
                $errors[] = "Type mismatch for parameter {$key}: expected {$expectedType}, got {$actualType}";
            }
        }

        return $errors;
    }

    protected function getValueType($value): string
    {
        return match (gettype($value)) {
            'integer' => 'integer',
            'double' => 'number',
            'boolean' => 'boolean',
            'array' => 'array',
            'NULL' => 'null',
            default => 'string'
        };
    }
}
