<?php

namespace App\Commands;

use App\Agent\Agent;
use App\Agent\Hooks;
use App\Tools\BrowseWebsiteTool;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\WriteFileTool;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class RunAgent extends Command
{
    protected $signature = 'run {task?}';

    public function handle(): void
    {
        $task = $this->argument('task');

        if (! $task) {
            $task = $this->ask('What do you want to do?');
        }

        $wrap = 120;

        $hooks = new Hooks([
            'start' => function ($task) {
                $this->newLine();
                $this->line(str_pad(' TASK', 120), 'fg=black;bg=bright-cyan');
                $this->line("<fg=cyan>{$task}</>");
            },
            'iteration' => function ($iteration) {
                $this->newLine();
                $this->line(str_pad(' ITERATION', 120), 'fg=black;bg=bright-white');
                $this->line("Step: {$iteration}");
            },
            'tool_execution' => function ($tool, $args) {
                $this->newLine();
                $this->line(str_pad(' TOOL EXECUTION', 120), 'fg=black;bg=bright-yellow');
                $this->table(['Tool', ...array_keys($args)], [[$tool, ...array_values($args)]]);
            },
            'thought' => function ($thought) {
                $this->newLine();
                $this->line(str_pad(' THOUGHT', 120), 'fg=black;bg=bright-blue');
                $this->line('<fg=blue>'.wordwrap($thought, 80).'</>');
            },
            'observation' => function ($observation) use ($wrap) {
                $this->newLine();
                $this->line(str_pad(' OBSERVATION', 120), 'fg=black;bg=bright-red');
                $this->line('<fg=magenta>'.Str::limit(wordwrap($observation, 80), $wrap * 10).'</>');
            },
            'evaluation' => function ($eval) use ($wrap) {
                $this->newLine();
                $this->line(str_pad(' EVALUATION', 120), 'fg=green;bg=black');
                $this->line('<fg=magenta>'.Str::limit(wordwrap($eval['feedback'], 80), $wrap * 10).'</>');

                foreach ($eval['tasks'] as $task) {
                    $this->line('<fg=magenta>    - '.Str::limit(wordwrap($task, 80), $wrap * 10).'</>');
                }
            },
            'final_answer' => function ($finalAnswer) use ($wrap) {
                $this->newLine();
                $this->line(str_pad(' FINAL ANSWER', 120), 'fg=blue;bg=black');
                $this->line(wordwrap($finalAnswer, $wrap));
            },
        ]);

        $agent = new Agent(
            tools: [
                new ReadFileTool(),
                new WriteFileTool(base_path('agent_output')),
                new SearchWebTool(),
                new BrowseWebsiteTool(),
                new RunCommandTool(),
            ],
            goal: 'Respond to the human as helpfully and accurately as possible. The human will ask you to do things, and you should do them.',
            hooks: $hooks,
        );

        $finalResponse = $agent->run($task);

    }
}
