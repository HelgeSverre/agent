<?php

namespace App\Tools;

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;

class WriteFileTool extends Tool
{
    protected string $baseDir;

    public function __construct($baseDir = null)
    {
        $this->baseDir = $baseDir ?? base_path('agent_output');
    }

    protected string $name = 'write_file';

    protected string $description = 'write a file from the local file system';

    public function run(
        #[Description('The name of the file to write')]
        string $filename,
        #[Description('The contents of the file')]
        string $content
    ): string {

        if (file_exists($this->baseDir) === false) {
            mkdir($this->baseDir);
        }

        // TODO: Protect against ../../ attacks from the LLM

        $path = $this->baseDir.'/'.$filename;

        file_put_contents($path, $content);

        return "File written to {$path}";
    }
}
