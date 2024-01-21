<?php

namespace App\Tools\EmailToolkit;

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use App\TextUtils;
use Carbon\Carbon;
use HelgeSverre\Brain\Facades\Brain;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

class SummarizeConversationHistoryTool extends Tool
{
    protected string $name = 'Summarize conversation history';

    protected string $description = 'Summarize the conversation history from an individual by their email address';

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? \Webklex\IMAP\Facades\Client::clone();
    }

    public function run(
        #[Description('The email address to search for')]
        string $emailAddress,
        #[Description('Only search for emails after this date')]
        ?Carbon $afterDate = null,
        #[Description('Only search for emails before this date')]
        ?Carbon $fromDate = null,
    ) {
        $this->client->connect();

        $messages = $this->client->getFolder('INBOX')->query()
            ->where(array_filter([
                'TEXT' => $emailAddress,
                'SINCE' => $afterDate,
                'BEFORE' => $fromDate,
            ]))
            ->limit(100)
            ->get();

        $results = [];

        foreach ($messages as $message) {
            $results[] = $this->transformToText($message);
        }

        $summary = $this->summarizeConversationHistory($results);

        return "Here is a summary of the conversation history with the individual with email '{$emailAddress}':\n {$summary}";
    }

    protected function summarizeConversationHistory(array $messages): string
    {
        $combined = implode("\n\n", $messages);

        // TODO: If history is too large, chunk the messages and recursively summarize them in batches.

        $prompt = 'Summarize this email exchange between the individuals, '
            .'extracting key details and arranging them chronologically in a concise manner.'
            .$combined;

        return Brain::fast()->text($prompt);
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
