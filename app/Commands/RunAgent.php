<?php

namespace App\Commands;

use App\Agent\Agent;
use App\Agent\Hooks;
use App\Tools\BrowseWebsiteTool;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\SpeakTool;
use App\Tools\WriteFileTool;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class RunAgent extends Command
{
    protected $signature = 'run {task?} 
        {--speak : Speak the final answer using the system\'s text-to-speech}
        {--save-session= : Save session with ID}
        {--resume= : Resume session by ID}
        {--parallel : Enable parallel tool execution}';

    public function handle(): void
    {
        $wrap = 120;
        $hooks = new Hooks;
        $agent = null;
        
        // Define tools array
        $tools = [
            new ReadFileTool,
            new WriteFileTool(base_path('output')),
            new SearchWebTool,
            new BrowseWebsiteTool,
            new RunCommandTool,
            new SpeakTool,
        ];
        
        // Check for resume first
        if ($resumeId = $this->option('resume')) {
            $agent = Agent::fromSession($resumeId, $tools, $hooks);
            
            if (!$agent) {
                $this->error("Session not found: {$resumeId}");
                return;
            }
            
            $this->info("Resuming session: {$resumeId}");
            $task = 'Resuming previous task';
        } else {
            $task = $this->argument('task');
            
            if (!$task) {
                $task = $this->ask('What do you want to do?');
            }
            
            // Show parallel mode status
            if ($this->option('parallel')) {
                $this->info('Parallel tool execution: ENABLED');
            }
        }

        $hooks->on('start', function ($task) {
            $this->newLine();
            $this->line('<fg=cyan>◆</> <fg=white;options=bold>Task:</> <fg=cyan>'.$task.'</>');
            $this->newLine();
        });

        $hooks->on('iteration', function ($iteration) {
            // Silent - just track the iteration number internally
        });

        $hooks->on('action', function ($action) {
            $icon = match ($action['action']) {
                'search_web' => '<fg=blue>⬡</>',
                'browse_website' => '<fg=green>⬢</>',
                'read_file' => '<fg=yellow>⬣</>',
                'write_file' => '<fg=magenta>»</>',
                'run_command' => '<fg=cyan>⬥</>',
                'final_answer' => '<fg=green>✓</>',
                'speak' => '<fg=yellow>▶</>',
                default => '<fg=gray>•</>'
            };

            $params = '';
            if (! empty($action['action_input'])) {
                if (isset($action['action_input']['searchTerm'])) {
                    $params = ' <fg=gray>"'.Str::limit($action['action_input']['searchTerm'], 40).'"</>';
                } elseif (isset($action['action_input']['url'])) {
                    $params = ' <fg=gray>'.parse_url($action['action_input']['url'], PHP_URL_HOST).'</>';
                } elseif (isset($action['action_input']['file_path'])) {
                    $params = ' <fg=gray>'.basename($action['action_input']['file_path']).'</>';
                } elseif (isset($action['action_input']['filename'])) {
                    $params = ' <fg=gray>'.$action['action_input']['filename'].'</>';
                }
            }

            $this->line($icon.' '.$action['action'].$params);
        });

        $hooks->on('thought', function ($thought) {
            $this->line('<fg=blue>◈</> <fg=gray>'.Str::limit($thought, 1000).'</>');
        });

        $hooks->on('observation', function ($observation) {
            // Special handling for parallel execution results
            if (str_contains($observation, '[Parallel Execution Complete]')) {
                $lines = explode("\n", $observation);
                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;
                    $this->line('  <fg=gray>└─ ' . Str::limit($line, 100) . '</>');
                }
            } elseif (str_contains($observation, '[Parallel Queue]')) {
                // Show queue messages
                $this->line('  <fg=cyan>└─ ' . $observation . '</>');
            } elseif (strlen($observation) > 200) {
                $this->line('  <fg=gray>└─ '.Str::limit($observation, 80).'...</>');
            }
        });

        $hooks->on('evaluation', function ($eval) {
            if (! $eval) {
                return;
            }

            if (isset($eval['status']) && $eval['status'] === 'completed') {
                $this->line('<fg=green>◉</> <fg=green>'.($eval['feedback'] ?? 'Completed').'</>');
            }
        });

        $hooks->on('max_iteration', function ($current, $mac) {
            $this->newLine();
            $this->line('<fg=red>✗</> <fg=white;options=bold>Max iterations reached:</>  <fg=red>'.$mac.'</> after <fg=yellow>'.$current.'</> iterations.');
            $this->newLine();
        });

        $hooks->on('final_answer', function ($finalAnswer) use ($wrap) {
            $this->newLine();
            $this->line('<fg=green>✓</> <fg=white;options=bold>Answer:</> '.wordwrap($finalAnswer, $wrap));
            $this->newLine();
        });
        
        $hooks->on('parallel_execution_start', function ($count) {
            $this->line('<fg=magenta>⟐</> <fg=white;options=bold>Executing '.$count.' tools in parallel...</>');
        });
        
        $hooks->on('parallel_execution_complete', function ($count) {
            $this->line('<fg=magenta>⟐</> <fg=green>Parallel execution complete ('.$count.' results)</>');
        });

        // Create agent if not resuming
        if (!$agent) {
            $agent = new Agent(
                tools: $tools,
                goal: 'Current date:'.date('Y-m-d')."\n".
                'Respond to the human as helpfully and accurately as possible.'.
                'The human will ask you to do things, and you should do them.',
                maxIterations: 20,
                hooks: $hooks,
                parallelEnabled: $this->option('parallel'),
            );
            
            // Enable session if requested
            if ($sessionId = $this->option('save-session')) {
                if (!$sessionId || $sessionId === '1') {
                    // Auto-generate ID from task
                    $sessionId = Str::slug(Str::limit($task, 30)) . '-' . date('Y-m-d-His');
                }
                
                $agent->enableSession($sessionId);
                $this->info("Session ID: {$sessionId}");
            }
        }

        $finalResponse = $agent->run($task);

        // For fun.
        if ($this->option('speak')) {
            shell_exec('say '.escapeshellarg(Str::of($finalResponse)->replace("\n", ' ')->trim()));
        }
    }
}
