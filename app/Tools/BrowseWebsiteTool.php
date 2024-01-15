<?php

namespace App\Tools;

use App\Agent\Tool;
use App\TextUtils;
use Illuminate\Support\Facades\Http;

class BrowseWebsiteTool implements Tool
{
    public function name(): string
    {
        return 'browse_website';
    }

    public function description(): string
    {
        return 'Get the contents of a website';
    }

    public function run(array $args = [])
    {

    }

    public function execute(...$args): string
    {
        $response = Http::get($args['url']);

        if ($response->failed()) {
            return 'Could not retrieve website contents for url: ' . $args['url'] . ' - ' . $response->status() . ' - ' . $response->body();
        }

        $text = TextUtils::cleanHtml($response->body());

        return "Website contents: \n\n{$text}";
    }
}
