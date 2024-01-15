<?php

namespace App\Tools;

use App\Agent\Tool;

class ReadFileTool implements Tool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'read a file from the local file system';
    }

    public function execute(...$args): string
    {
        if (file_exists('./output') === false) {
            mkdir('./output');
        }

        return 'File contents: '.file_get_contents('./output/'.$args['file_name']);
    }
}
