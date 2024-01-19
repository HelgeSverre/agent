<?php

namespace App\Tools;

use App\Agent\Tool\Tool;

class ReadFileTool extends Tool
{
    protected string $name = 'read_file';

    protected string $description = 'read a file from the local file system';

    protected string $baseDir;

    public function __construct($baseDir = null)
    {
        $this->baseDir = $baseDir ?? base_path('output');
    }

    public function run(string $filename): string
    {
        if (file_exists($this->baseDir) === false) {
            mkdir($this->baseDir);
        }

        $path = $this->baseDir.'/'.$filename;

        return 'File contents: '.$path;
    }
}
