<?php

namespace App\Tools;

use App\Agent\Tool\Attributes\AsTool;
use App\Agent\Tool\Tool;
use Crwlr\Html2Text\Html2Text;
use Illuminate\Support\Facades\Http;

#[AsTool(
    name: 'browse_website',
    description: 'Get the contents of a website'
)]
class BrowseWebsiteTool extends Tool
{
    public function run(string $url): string
    {
        $response = Http::get($url);

        if ($response->failed()) {
            return sprintf('Could not retrieve website contents for url: %s - %s - %s', $url, $response->status(), $response->body());
        }

        $html = $response->body();

        $text = Html2Text::convert($html);

        return "Website contents: \n\n{$text}";
    }
}
