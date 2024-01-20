<?php

use App\Tools\Trello\TrelloService;

it('returns an array of ToolArgument objects from arguments method', function () {
    $trello = new TrelloService(
        apiKey: env('TRELLO_API_KEY'),
        apiToken: env('TRELLO_API_TOKEN')
    );

    dd($trello->listWorkspaces());

});
