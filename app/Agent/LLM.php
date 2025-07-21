<?php

namespace App\Agent;

use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Throwable;

class LLM
{
    const model = 'gpt-4.1-mini';

    public static function json($prompt, ?int $max = null): ?array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => self::model,
                'max_tokens' => $max ?? 4096,
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],

                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            return self::toJson($response);
        } catch (Throwable) {
            return null;
        }
    }

    public static function toText(CreateResponse $response, $fallback = null): ?string
    {
        return rescue(fn () => $response->choices[0]->message->content, rescue: $fallback);
    }

    public static function toJson(CreateResponse $response, $fallback = null): ?array
    {
        return rescue(fn () => json_decode(self::toText($response), associative: true), rescue: $fallback);
    }

    public static function functionCall(array $tools): FunctionCallBuilder
    {
        return new FunctionCallBuilder($tools);
    }
}
