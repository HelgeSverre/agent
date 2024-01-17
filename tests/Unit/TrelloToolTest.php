<?php

namespace Tests\Unit;

use App\Tools\TrelloTool;
use Illuminate\Support\Facades\Http;
use function Pest\Laravel\assertStringContainsString;

it('lists Trello boards', function () {
    $apiKey = 'test_api_key';
    $token = 'test_token';
    $fakeResponse = [
        ['name' => 'Board 1', 'url' => 'http://trello.com/board1'],
        ['name' => 'Board 2', 'url' => 'http://trello.com/board2'],
    ];

    Http::fake([
        'api.trello.com/1/members/me/boards*' => Http::response($fakeResponse, 200),
    ]);

    $trelloTool = new TrelloTool($apiKey, $token);
    $result = $trelloTool->run();

    $expectedMarkdown = "- [Board 1](http://trello.com/board1)\n- [Board 2](http://trello.com/board2)";
    assertStringContainsString($expectedMarkdown, $result);
});
