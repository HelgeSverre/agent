<?php

namespace App\Tools;

use App\Agent\Tool\Tool;
use Illuminate\Support\Facades\Http;

class TrelloTool extends Tool
{
    protected string $name = 'list_trello_boards';

    protected string $description = 'List all Trello boards visible to the user';

    public function run(string $apiKey, string $token): string
    {
        $url = "https://api.trello.com/1/members/me/boards?fields=name,url&key={$apiKey}&token={$token}";
        $response = Http::get($url);

        if ($response->failed()) {
            return sprintf('Could not retrieve Trello boards: %s - %s', $response->status(), $response->body());
        }

        $boards = $response->json();
        $markdownList = collect($boards)->map(function ($board) {
            return "- [{$board['name']}]({$board['url']})";
        })->implode("\n");

        return "Trello Boards:\n\n{$markdownList}";
    }
}
