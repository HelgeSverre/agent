<?php

namespace App\Tools;

use App\Agent\Tool;
use Symfony\Component\Process\Process;

class RunCommandTool implements Tool
{
    public function name(): string
    {
        return 'run_command';
    }

    public function description(): string
    {
        return 'run a command on the terminal and get the output, useful for running command line tools or listing files etc';
    }

    public function execute(...$args): string
    {
        $command = $args['command'];

        // TODO: if command is "rm" or something dangerous like that, throw "UnsafeCommandException"

        $process = new Process(explode(' ', $command));
        $process->run();

        return $process->getOutput();
    }
}
