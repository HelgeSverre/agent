<?php

namespace App\Tools;

use App\Agent\Tool\Attributes\AsTool;
use App\Agent\Tool\Attributes\Description;
use App\Agent\Tool\Tool;
use Symfony\Component\Process\Process;

#[AsTool(
    name: 'run_command',
    description: 'Run a command on the terminal and get the output, useful for running command line tools or listing files etc'
)]
class RunCommandTool extends Tool
{
    public function run(
        #[Description('The command to run')]
        string $command,
    ): string {
        $process = new Process(explode(' ', $command));
        $process->run();

        return $process->getOutput();
    }
}
