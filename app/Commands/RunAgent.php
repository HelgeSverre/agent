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
    protected $signature = 'run {task?} {--speak : Speak the final answer using the system\'s text-to-speech}';

    public function handle(): void
    {
        $task = $this->argument('task');

        if (! $task) {
            $task = $this->ask('What do you want to do?');
        }

        $wrap = 120;

        $hooks = new Hooks();
        
        $hooks->on('start', function ($task) {
            $this->newLine();
            $this->line(str_pad(' TASK', 120), 'fg=black;bg=bright-cyan');
            $this->line('<fg=cyan>'.wordwrap($task, 80).'</>');
        });
        
        $hooks->on('iteration', function ($iteration) {
            $this->newLine();
            $this->line(str_pad(' ITERATION', 120), 'fg=black;bg=bright-white');
            $this->line("Step: {$iteration}");
        });
        
        $hooks->on('action', function ($action) {
            $this->newLine();
            $this->line(str_pad(' TOOL EXECUTION', 120), 'fg=black;bg=bright-yellow');
            $this->line("Tool: {$action['action']}");
            $this->line('Args:');
            $this->line(json_encode($action['action_input'] ?? [], JSON_PRETTY_PRINT));
        });
        
        $hooks->on('thought', function ($thought) {
            $this->newLine();
            $this->line(str_pad(' THOUGHT', 120), 'fg=black;bg=bright-blue');
            $this->line('<fg=blue>'.wordwrap($thought, 80).'</>');
        });
        
        $hooks->on('observation', function ($observation) use ($wrap) {
            //                $this->newLine();
            //                $this->line(str_pad(' OBSERVATION', 120), 'fg=black;bg=bright-red');
            //                $this->newLine();
            $this->line('<fg=gray>'.Str::limit(wordwrap($observation, 80), $wrap * 4).'</>');
        });
        
        $hooks->on('evaluation', function ($eval) use ($wrap) {
            $this->newLine();
            $this->line(str_pad(' EVALUATION', 120), 'fg=green;bg=black');
            $this->newLine();
            
            if (!$eval) {
                $this->line('<fg=red>Evaluation failed - no response from LLM</>');
                return;
            }
            
            $this->line('<fg=magenta>'.Str::limit(wordwrap($eval['feedback'] ?? 'No feedback', 80), $wrap * 10).'</>');

            if (filled($eval['tasks'])) {
                $this->newLine();
                $this->line(str_pad(' EVALUATION - TASKS REMAINING', 120), 'fg=green;bg=green');
                $this->newLine();
                foreach ($eval['tasks'] as $task) {
                    $this->line('<fg=magenta>    - '.Str::limit(wordwrap($task, 80), $wrap * 10).'</>');
                }
            }
        });
        
        $hooks->on('final_answer', function ($finalAnswer) use ($wrap) {
            $this->newLine();
            $this->line(str_pad(' FINAL ANSWER', 120), 'fg=black;bg=bright-yellow');
            $this->newLine();
            
            $this->line(wordwrap($finalAnswer, $wrap));
        });

        $agent = new Agent(
            tools: [
                new ReadFileTool,
                new WriteFileTool(base_path('output')),
                new SearchWebTool,
                new BrowseWebsiteTool,
                new RunCommandTool,
                // new SearchEmailTool(),
                // new SummarizeConversationHistoryTool(),
                // new CreateDraftEmailTool(),
            ],
            goal: 'Current date:'.date('Y-m-d')."\n".
            'Respond to the human as helpfully and accurately as possible.'.
            'The human will ask you to do things, and you should do them.',
            hooks: $hooks,
        );

        $finalResponse = $agent->run($task);

        // For fun.
        if ($this->option('speak')) {
            shell_exec('say '.escapeshellarg(Str::of($finalResponse)->replace("\n", ' ')->trim()));
        }
    }
}
