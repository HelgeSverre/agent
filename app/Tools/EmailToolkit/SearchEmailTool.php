<?php

namespace App\Tools\EmailToolkit;

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Stevebauman\Hypertext\Transformer;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

class SearchEmailTool extends Tool
{
    protected string $name = 'Search Email';

    protected string $description = 'Search for emails by keyword and date range';

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? \Webklex\IMAP\Facades\Client::clone();
    }

    public function run(
        #[Description('The search query to search for')]
        string $searchQuery,
        #[Description('Only search for emails after this date')]
        ?Carbon $afterDate = null,
        #[Description('Only search for emails before this date')]
        ?Carbon $fromDate = null
    ) {
        $this->client->connect();

        $messages = $this->client->getFolder('INBOX')->query()
            ->where(array_filter([
                'TEXT' => $searchQuery,
                'SINCE' => $afterDate,
                'BEFORE' => $fromDate,

            ]))
            ->limit(100)
            ->get();

        $results = [];

        foreach ($messages as $message) {
            $results[] = $this->transformToText($message);
        }

        return "The email search returned the following emails: \n".implode('\n\n', $results);
    }

    protected function transformToText(Message $message): string
    {

        $body = $message->getTextBody();

        if (! $body) {
            $body = (new Transformer)
                ->keepLinks()
                ->keepNewLines()
                ->toText($message->getHTMLBody());
        }

        return implode("\n", [
            'Subject: '.$message->getSubject(),
            'From: '.$message->getFrom(),
            'Date: '.$message->getDate(),
            'Text: '.Str::of($body)->squish()->trim()->toString(),
        ]);

    }
}
