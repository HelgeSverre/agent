<?php

namespace App\Commands;

use App\Agent\Agent;
use App\Agent\Hooks;
use App\Tools\BrowseWebsiteTool;
use App\Tools\EmailToolkit\CreateDraftEmailTool;
use App\Tools\EmailToolkit\SearchEmailTool;
use App\Tools\EmailToolkit\SummarizeConversationHistoryTool;
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
                $this->newLine(2);
                $this->line(str_pad(' TASK', 120), 'fg=black;bg=bright-cyan');
                $this->newLine();
                $this->line('<fg=cyan>'.wordwrap($task, 80).'</>');
            },
            'iteration' => function ($iteration) {
                $this->newLine(2);
                $this->line(str_pad(' ITERATION', 120), 'fg=black;bg=bright-white');
                $this->newLine();
                $this->line("Step: {$iteration}");
            },
            'tool_execution' => function ($tool, $args) {
                $this->newLine(2);
                $this->line(str_pad(' TOOL EXECUTION', 120), 'fg=black;bg=bright-yellow');
                $this->newLine();
                $this->line("Tool name: {$tool}");
                $this->line('Tool Arguments:');
                $this->line(json_encode($args, JSON_PRETTY_PRINT));
            },
            'thought' => function ($thought) {
                $this->newLine(2);
                $this->line(str_pad(' THOUGHT', 120), 'fg=black;bg=bright-blue');
                $this->newLine();
                $this->line('<fg=blue>'.wordwrap($thought, 80).'</>');
            },
            'observation' => function ($observation) use ($wrap) {
                $this->newLine(2);
                $this->line(str_pad(' OBSERVATION', 120), 'fg=black;bg=bright-red');
                $this->newLine();
                $this->line('<fg=magenta>'.Str::limit(wordwrap($observation, 80), $wrap * 20).'</>');
            },
            'evaluation' => function ($eval) use ($wrap) {
                $this->newLine(2);
                $this->line(str_pad(' EVALUATION', 120), 'fg=green;bg=black');
                $this->newLine();
                $this->line('<fg=magenta>'.Str::limit(wordwrap($eval['feedback'], 80), $wrap * 10).'</>');

                foreach ($eval['tasks'] as $task) {
                    $this->line('<fg=magenta>    - '.Str::limit(wordwrap($task, 80), $wrap * 10).'</>');
                }
            },
            'final_answer' => function ($finalAnswer) use ($wrap) {
                $this->newLine(2);
                $this->line(str_pad(' FINAL ANSWER', 120), 'fg=blue;bg=black');
                $this->newLine();
                $this->line(wordwrap($finalAnswer, $wrap));
            },
        ]);

        $agent = new Agent(
            tools: [
                new ReadFileTool(),
                new WriteFileTool(base_path('output')),
                new SearchWebTool(),
                new BrowseWebsiteTool(),
                new RunCommandTool(),
                new SearchEmailTool(),
                new SummarizeConversationHistoryTool(),
                new CreateDraftEmailTool(),
            ],
            goal: 'Current date:'.date('Y-m-d')."\n".
            'Respond to the human as helpfully and accurately as possible.'.
            'The human will ask you to do things, and you should do them.',
            hooks: $hooks,
        );

        $finalResponse = $agent->run(
            'Fetch the latest 50 emails from BOB.no,'.
            'find all the the people contact i have had written conversations with from BOB, '.
            'store the names and emails in contacts.txt, '.
            'then provide a summary of each individual person from the past year'.
            'and save them as individual summary files using the contact name as the filename');

    }
}
