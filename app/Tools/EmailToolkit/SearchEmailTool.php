<?php

namespace App\Tools\EmailToolkit;

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use App\TextUtils;
use Carbon\Carbon;
use Illuminate\Support\Str;
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
        ?Carbon $fromDate = null,
        #[Description('Which page to return')]
        int $page = 1,
        #[Description('How many results to return per page (try to keep this number lower than 20)')]
        int $limit = 10,
    ) {
        $this->client->connect();

        $messages = $this->client->getFolder('INBOX')->query()
            ->where(array_filter([
                'TEXT' => $searchQuery,
                'SINCE' => $afterDate,
                'BEFORE' => $fromDate,
            ]))
            ->setPage($page)
            ->limit($limit)
            ->get();

        $results = [];

        foreach ($messages as $message) {
            $results[] = $this->transformToText($message);
        }

        return "The email search returned the following emails: \n".implode("\n\n", $results);
    }

    protected function transformToText(Message $message): string
    {
        $body = $message->getHTMLBody() ?: $message->getTextBody();

        $body = Str::of(TextUtils::cleanHtml($body))
            ->stripTags()
            ->replaceMatches('/(\W)\1+/', '$1')
            ->squish()
            ->trim();

        return implode("\n", [
            'Subject: '.$message->getSubject(),
            'From: '.$message->getFrom(),
            'Date: '.$message->getDate(),
            'Text: '.TextUtils::cleanHtml($body),
        ]);

    }
}
