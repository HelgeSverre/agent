<?php

namespace App\Tools;

class ReadFileTool
{
    protected string $name = 'read_file';

    protected string $description = 'read a file from the local file system';

    public function run(string $fileName): string
    {
        if (file_exists('./output') === false) {
            mkdir('./output');
        }

        return 'File contents: '.file_get_contents('./output/'.$fileName);
    }
}
