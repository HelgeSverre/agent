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

        // Create functions array for OpenAI
        $functions = [];

        // Add all tool functions
        if (isset($this->tools['functions'])) {
            $functions = array_merge($functions, $this->tools['functions']);
        }

        // Add final_answer function if provided
        if (isset($this->tools['final_answer'])) {
            $functions[] = $this->tools['final_answer'];
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
                'functions' => $functions,
                'function_call' => 'auto',
            ]);

            // Process the response
            $message = $response->choices[0]->message;

            // Check if there's a function call
            if (isset($message->functionCall)) {
                return [
                    'function_call' => [
                        'name' => $message->functionCall->name,
                        'arguments' => json_decode($message->functionCall->arguments, true),
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
