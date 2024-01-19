<?php

namespace App\Tools\EmailToolkit;

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use Illuminate\Support\Str;
use Stevebauman\Hypertext\Transformer;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

class CreateDraftEmailTool extends Tool
{
    protected string $name = 'Create Draft Email';

    protected string $description = 'Create a draft email';

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? \Webklex\IMAP\Facades\Client::clone();
    }

    public function run(
        #[Description('The subject of the email')]
        string $subject,
        #[Description('The body of the email, plain text only')]
        string $body,
        #[Description('The email address to send the email to')]
        string $to
    ) {
        $this->client->connect();

        $config = $this->client->getAccountConfig();
        $from = $config['username'];

        $draft = $this->client->getFolder('[Gmail]/Drafts')
            ->appendMessage(
                "From: $from
To: $to
Subject: $subject
Content-Type: text/plain;
	charset=\"us-ascii\"
Content-Transfer-Encoding: quoted-printable

$body",
                ['Draft']
            );

        return 'The draft email was created';
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
            'Date: '.$message->getDate(),
            'Text: '.Str::of($body)->squish()->trim()->toString(),
        ]);

    }
}
