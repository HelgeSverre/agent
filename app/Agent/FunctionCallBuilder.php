<?php

namespace App\Agent;

use OpenAI\Laravel\Facades\OpenAI;
use Throwable;

class FunctionCallBuilder
{
    protected array $tools;

    protected ?string $prompt = null;

    public function __construct(array $tools)
    {
        $this->tools = $tools;
    }

    public function get(string $prompt): array
    {
        $this->prompt = $prompt;

        // Build tool definitions from functions
        $toolDefinitions = [];

        if (isset($this->tools['functions'])) {
            foreach ($this->tools['functions'] as $fn) {
                $toolDefinitions[] = ['type' => 'function', 'function' => $fn];
            }
        }

        if (isset($this->tools['final_answer'])) {
            $toolDefinitions[] = ['type' => 'function', 'function' => $this->tools['final_answer']];
        }

        try {
            $response = OpenAI::chat()->create([
                'model' => LLM::model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'tools' => $toolDefinitions,
                'tool_choice' => 'auto',
            ]);

            // Process the response
            $message = $response->choices[0]->message;

            // Check if there's a tool call
            if (isset($message->toolCalls) && count($message->toolCalls) > 0) {
                $toolCall = $message->toolCalls[0];

                return [
                    'function_call' => [
                        'name' => $toolCall->function->name,
                        'arguments' => json_decode($toolCall->function->arguments, true),
                    ],
                    'thought' => $message->content ?? null,
                ];
            } elseif ($message->content) {
                // If no function call, return the content as thought
                return [
                    'thought' => $message->content,
                ];
            }

            return [];
        } catch (Throwable $e) {
            // Log the error for debugging
            error_log('FunctionCallBuilder error: '.$e->getMessage());
            error_log('Stack trace: '.$e->getTraceAsString());

            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
