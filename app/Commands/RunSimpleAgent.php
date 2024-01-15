<?php

namespace App\Commands;

use App\Agent\Agent;
use App\Agent\SimpleAgent;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\WriteFileTool;
use LaravelZero\Framework\Commands\Command;

class RunSimpleAgent extends Command
{
    protected $signature = 'simple {task?}';

    public function handle(): void
    {
        $task = $this->argument('task');

        if (!$task) {
            $task = $this->ask('What do you want to do?');
        }

        $this->comment("Task: \n" . wordwrap($task, 80));

        $agent = new SimpleAgent(
            tools: [
                new ReadFileTool(),
                new WriteFileTool(),
                new SearchWebTool(),
                new RunCommandTool(),
            ],
            goal: 'Respond to the human as helpfully and accurately as possible. The human will ask you to do things, and you should do them.',
        );

        $finalResponse = $agent->run($task);

        $this->warn("Final response: {$finalResponse}");

    }


}
