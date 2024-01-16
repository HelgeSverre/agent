<?php

namespace App\Tools;

use Illuminate\Support\Facades\Http;

class SearchWebTool
{
    public function name(): string
    {
        return 'search_web';
    }

    public function description(): string
    {
        return 'search the web for a specific search term';
    }

    public function run(string $searchTerm, int $numResults = 5): string
    {
        /** @noinspection LaravelFunctionsInspection */
        $response = Http::withHeader('X-Subscription-Token', env('BRAVE_API_KEY'))
            ->acceptJson()
            ->asJson()
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $searchTerm,
                'count' => $numResults,
            ]);

        if ($response->status() == 422) {
            return 'The tool returned an error, the input arguments might be wrong';
        }

        if ($response->failed()) {
            return 'The tool returned an error.';
        }

        return $response
            ->collect('web.results')
            ->map(fn ($result) => [
                'title' => $result['title'],
                'url' => $result['url'],
                'description' => strip_tags($result['description']),
            ])
            ->toJson(JSON_PRETTY_PRINT);
    }
}
