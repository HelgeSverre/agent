<?php

namespace App\Tools;

use App\Agent\Tool;

class BrowseWebsiteTool implements Tool
{
    public function name(): string
    {
        return 'browse_website';
    }

    public function description(): string
    {
        return 'Get the contents of a website';
    }

    public function execute(...$args): string
    {
        // Implementation to get website contents
        // Use $args to get 'url'
        // Return website contents or result string
    }
}
