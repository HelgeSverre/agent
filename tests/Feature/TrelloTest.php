<?php

use App\Tools\Trello\TrelloService;

beforeEach(function () {
    //
})->skip(blank(env('TRELLO_API_KEY')), 'Trello API key is not set');

it('returns an array of ToolArgument objects from arguments method', function () {
    $trello = new TrelloService(
        apiKey: env('TRELLO_API_KEY'),
        apiToken: env('TRELLO_API_TOKEN')
    );

    dd($trello->listWorkspaces());

});
