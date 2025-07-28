<?php

namespace App\Commands;

use App\Agent\Agent;
use App\Agent\Hooks;
use App\Agent\LLM;
use App\Agent\Planning\Planner;
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
        {--parallel : Enable parallel tool execution}
        {--plan : Create and show execution plan before running}
        {--chat : Enable conversational mode for continuous interaction}';

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
        
        // Register all hooks before using them
        $this->registerHooks($hooks, $wrap);
        
        // Handle planning if requested
        $executionPlan = null;
        if ($this->option('plan') && !$this->option('resume')) {
            $planner = new Planner();
            $this->info('Creating execution plan...');
            $executionPlan = $planner->createPlan($task, $tools);
            
            // Trigger plan hook with raw plan data
            $hooks->trigger('plan', $executionPlan);
            
            // Ask for confirmation
            if (!$this->confirm('Do you want to proceed with this plan?')) {
                $this->info('Execution cancelled.');
                return;
            }
            $this->newLine();
        }

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
            
            // Set execution plan if created
            if ($executionPlan) {
                $agent->setExecutionPlan($executionPlan);
            }
            
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

        // Check if chat mode is enabled
        if ($this->option('chat')) {
            $this->runChatMode($agent, $task);
        } else {
            $finalResponse = $agent->run($task);

            // For fun.
            if ($this->option('speak')) {
                shell_exec('say '.escapeshellarg(Str::of($finalResponse)->replace("\n", ' ')->trim()));
            }
        }
    }
    
    protected function runChatMode(Agent $agent, string $initialTask): void
    {
        $this->info('◈ Chat mode enabled. Type "exit" or "quit" to end the conversation.');
        $this->newLine();
        
        $task = $initialTask;
        $conversationActive = true;
        
        while ($conversationActive) {
            // Run the agent with current task
            $finalResponse = $agent->run($task);
            
            // Speak if enabled
            if ($this->option('speak')) {
                shell_exec('say '.escapeshellarg(Str::of($finalResponse)->replace("\n", ' ')->trim()));
            }
            
            // Visual separator
            $this->newLine();
            $this->line('<fg=gray>' . str_repeat('─', 80) . '</>');
            $this->newLine();
            
            // Get next input
            $nextInput = $this->ask('>');
            
            if (in_array(strtolower($nextInput), ['exit', 'quit', 'bye', 'q'])) {
                $this->info('◉ Ending chat session. Goodbye!');
                $conversationActive = false;
            } else {
                // Enhance task with context if it seems like a follow-up
                $task = $this->enhanceTaskWithContext($nextInput, $finalResponse);
                
                // Reset agent for next task but maintain context
                $agent->resetForNextTask();
            }
        }
    }
    
    protected function enhanceTaskWithContext(string $input, string $previousResponse): string
    {
        // Use LLM to classify if this is a follow-up
        $prompt = "Given the previous conversation and new input, determine if the new input is a follow-up question or a completely new task.

Previous response summary: " . Str::limit($previousResponse, 200) . "
New input: {$input}

Analyze this and return a JSON response with this structure:
{
    \"is_follow_up\": true/false,
    \"context_needed\": \"Brief context if follow-up, or empty string\",
    \"enhanced_input\": \"The original input with added context if needed\"
}

Be concise. If it's a follow-up, add minimal context in parentheses.";

        $result = LLM::json($prompt);
        
        if ($result && isset($result['enhanced_input'])) {
            return $result['enhanced_input'];
        }
        
        // Fallback if LLM fails
        return $input;
    }
    
    protected function registerHooks(Hooks $hooks, int $wrap): void
    {
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

            $this->line($icon . ' ' . $action['action'] . $params);
        });

        $hooks->on('thought', function ($thought) {
            $wrapped = wordwrap($thought, 77, "\n   ");
            $this->line('<fg=blue>◈</> <fg=gray>' . $wrapped . '</>');
        });

        $hooks->on('observation', function ($observation) {
            // Special handling for parallel execution results
            if (str_contains($observation, '[Parallel Execution Complete]')) {
                $lines = explode("\n", $observation);
                $inResults = false;
                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;
                    
                    if (str_contains($line, '[Parallel Execution Complete]')) {
                        $this->line('  <fg=magenta>◉</> <fg=white;options=bold>' . trim($line) . '</>');
                    } elseif (str_contains($line, 'Executed') && str_contains($line, 'tools:')) {
                        $this->line('  <fg=gray>' . $line . '</>');
                    } elseif (str_starts_with(trim($line), '-')) {
                        $this->line('    <fg=cyan>' . $line . '</>');
                    } elseif (str_contains($line, 'Results:')) {
                        $this->line('  <fg=gray>' . $line . '</>');
                        $inResults = true;
                    } elseif ($inResults && str_contains($line, '[✓')) {
                        // Successful result
                        $this->line('    <fg=green>' . Str::limit($line, 120) . '</>');
                    } elseif ($inResults && str_contains($line, '[✗')) {
                        // Failed result
                        $this->line('    <fg=red>' . Str::limit($line, 120) . '</>');
                    } elseif ($inResults) {
                        // Result content
                        $this->line('    <fg=gray>' . Str::limit($line, 100) . '</>');
                    } else {
                        $this->line('  <fg=gray>' . $line . '</>');
                    }
                }
            } elseif (str_contains($observation, '[Parallel Queue]')) {
                // Show queue messages
                $this->line('  <fg=cyan>⟐</> ' . str_replace('[Parallel Queue] ', '', $observation));
            } elseif (str_contains($observation, '[Skipped]')) {
                // Show skip messages
                $this->line('  <fg=yellow>⟐</> ' . str_replace('[Skipped] ', '', $observation));
            } else {
                // Regular observations - properly indent multi-line content
                $lines = explode("\n", $observation);
                if (count($lines) > 1 || strlen($observation) > 77) {
                    $this->line('   <fg=gray>↳ ' . array_shift($lines) . '</>');
                    foreach ($lines as $line) {
                        if (!empty(trim($line))) {
                            $this->line('     <fg=gray>' . Str::limit($line, 75) . '</>');
                        }
                    }
                } else {
                    $this->line('   <fg=gray>↳ ' . $observation . '</>');
                }
            }
        });
        
        $hooks->on('compressed_context', function ($context) {
            $this->line('<fg=yellow>◊</> <fg=gray>' . $context . '</>');
        });

        $hooks->on('evaluation', function ($eval) {
            if (! $eval) {
                return;
            }

            if (isset($eval['status']) && $eval['status'] === 'completed') {
                $feedback = $eval['feedback'] ?? 'Completed';
                $wrapped = wordwrap($feedback, 77, "\n   ");
                $this->line('<fg=green>◉</> <fg=white;options=bold>Evaluation:</>');
                $this->line('   <fg=green>' . $wrapped . '</>');
            }
        });

        $hooks->on('max_iteration', function ($current, $mac) {
            $this->newLine();
            $this->line('<fg=red>✗</> <fg=white;options=bold>Max iterations reached:</>  <fg=red>'.$mac.'</> after <fg=yellow>'.$current.'</> iterations.');
            $this->newLine();
        });

        $hooks->on('final_answer', function ($finalAnswer) use ($wrap) {
            $this->newLine();
            $wrapped = wordwrap($finalAnswer, 77, "\n   ");
            $this->line('<fg=green>✓</> <fg=white;options=bold>Final answer:</>');
            $this->line('   <fg=white>' . $wrapped . '</>');
            $this->newLine();
        });
        
        $hooks->on('parallel_execution_start', function ($count) {
            $this->line('<fg=magenta>⟐</> <fg=white;options=bold>Executing '.$count.' tools in parallel...</>');
        });
        
        $hooks->on('parallel_execution_complete', function ($count) {
            $this->line('<fg=magenta>⟐</> <fg=green>Parallel execution complete ('.$count.' results)</>');
        });
        
        $hooks->on('plan', function ($plan) {
            $this->newLine();
            $this->line('<fg=cyan>◍</> <fg=white;options=bold>Execution Plan</>');
            $this->line('   <fg=gray>' . str_repeat('─', 60) . '</>');
            
            // Summary
            $wrapped = wordwrap($plan['summary'], 77, "\n   ");
            $this->line('   <fg=white>' . $wrapped . '</>');
            $this->newLine();
            
            // Metadata
            $complexityColor = match($plan['complexity']) {
                'simple' => 'green',
                'moderate' => 'yellow',
                'complex' => 'red',
                default => 'gray'
            };
            
            $this->line('   <fg=gray>Complexity:</> <fg=' . $complexityColor . ';options=bold>' . ucfirst($plan['complexity']) . '</>');
            $this->line('   <fg=gray>Steps:</> <fg=white;options=bold>' . count($plan['steps']) . '</>');
            $this->line('   <fg=gray>Estimated tools:</> <fg=white;options=bold>' . $plan['estimated_tools'] . '</>');
            $this->newLine();
            
            // Steps
            $this->line('   <fg=white;options=bold>Steps:</>');
            $this->line('   <fg=gray>' . str_repeat('─', 60) . '</>');
            
            foreach ($plan['steps'] as $step) {
                $this->newLine();
                
                // Step number and description
                $stepDesc = wordwrap($step['description'], 70, "\n      ");
                $this->line('   <fg=white;options=bold>' . $step['step_number'] . '.</> <fg=white>' . $stepDesc . '</>');
                
                // Tools
                if (!empty($step['tools'])) {
                    $this->line('      <fg=blue>Tools: ' . implode(', ', $step['tools']) . '</>');
                }
                
                // Parallelization
                if ($step['can_parallelize']) {
                    $this->line('      <fg=magenta>⟐ Can run in parallel</>');
                }
                
                // Dependencies
                if (!empty($step['depends_on'])) {
                    $this->line('      <fg=gray>Depends on: Step ' . implode(', ', $step['depends_on']) . '</>');
                }
            }
            
            $this->newLine();
            $this->line('   <fg=gray>' . str_repeat('─', 60) . '</>');
            $this->newLine();
        });
    }
}
