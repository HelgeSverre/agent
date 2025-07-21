<?php

namespace App\Tools;

use App\Agent\Tool\Tool;
use Crwlr\Html2Text\Html2Text;
use Illuminate\Support\Facades\Http;

class BrowseWebsiteTool extends Tool
{
    protected string $name = 'browse_website';

    protected string $description = 'Get the contents of a website';

    public function run(string $url): string
    {
        $response = Http::get($url);

        if ($response->failed()) {
            return sprintf('Could not retrieve website contents for url: %s - %s - %s', $url, $response->status(), $response->body());
        }

        $html = $response->body();

        $text = Html2Text::convert($html);
        //        $text = TextUtils::cleanHtml($html);

        return "Website contents: \n\n{$text}";
    }
}
