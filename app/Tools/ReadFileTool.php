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

        if (! file_exists($path)) {
            return "Error: File not found at {$path}";
        }

        $contents = file_get_contents($path);

        return "File contents:\n\n{$contents}";
    }
}
