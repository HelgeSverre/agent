<?php

namespace App\Tools\Trello;

use Illuminate\Support\Facades\Http;

class TrelloService
{
    public function __construct(
        protected string $apiKey,
        protected string $apiToken
    )
    {
    }

    protected function sendRequest($endpoint, $params = [])
    {
        return Http::get("https://api.trello.com/1/{$endpoint}", [
            ...$params,
            "key" => $this->apiKey,
            "token" => $this->apiToken,
        ])->json();
    }

    public function listWorkspaces()
    {
        // Assuming you want to list the teams (workspaces)
        return $this->sendRequest('members/me/organizations',[
            'fields' => 'id,name,desc,displayName,url',
        ]);
    }

    public function listBoards($workspaceId)
    {
        return $this->sendRequest("organizations/{$workspaceId}/boards");
    }

    public function listCards($boardId)
    {
        return $this->sendRequest("boards/{$boardId}/cards");
    }

    public function searchBoards($query, $limit = 10)
    {
        return $this->sendRequest('search', [
            'query' => $query,
            'modelTypes' => 'boards',
            'board_fields' => 'name,url',
            'boards_limit' => $limit,
        ]);
    }
}
