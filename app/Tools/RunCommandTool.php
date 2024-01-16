<?php

namespace App\Tools;

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use Symfony\Component\Process\Process;

class RunCommandTool extends Tool
{
    protected $name = 'run_command';

    protected $description = 'Run a command on the terminal and get the output, useful for running command line tools or listing files etc';

    public function run(
        #[Description('The command to run')]
        string $command,
    ): string {
        $process = new Process(explode(' ', $command));
        $process->run();

        return $process->getOutput();
    }
}
