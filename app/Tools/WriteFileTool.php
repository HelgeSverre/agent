<?php

namespace App\Tools;

use App\Agent\Tool;

class WriteFileTool implements Tool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'write a file from the local file system';
    }

    public function execute(...$args): string
    {
        if (file_exists('./output') === false) {
            mkdir('./output');
        }
        file_put_contents('./output/'.$args['file_name'], $args['file_content']);

        return 'File written.';
    }
}
