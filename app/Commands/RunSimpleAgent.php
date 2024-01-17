<?php

namespace App\Commands;

use App\Agent\Hooks;
use App\Agent\SimpleAgent;
use App\Tools\BrowseWebsiteTool;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\WriteFileTool;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class RunSimpleAgent extends Command
{
    protected $signature = 'simple {task?}';

    public function handle(): void
    {

        $task = $this->argument('task');

        if (!$task) {
            $task = $this->ask('What do you want to do?');
        }

        $this->renderTextboxWithHeader('Task', $task);
        die;

        $hooks = new Hooks([
            'start' => fn($task) => $this->renderTextboxWithHeader('Task', $task),
            'iteration' => fn($iteration) => intro("Step: {$iteration}"),
            'tool_execution' => fn($tool, $args) => $this->table(['Tool', ...array_keys($args)], [[$tool, ...array_values($args)]]),
            'thought' => fn($thought) => \Laravel\Prompts\info("Thought:\n" . wordwrap($thought, 80)),
            'observation' => fn($observation) => warning('Observation: ' . Str::limit(wordwrap($observation, 80), 80 * 10)),
            'final_answer' => fn($finalAnswer) => note(wordwrap($finalAnswer, 80)),
            'next_step' => fn($step) => dump($step),
        ]);

        $agent = new SimpleAgent(
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

    public function renderTextboxWithHeader(string $header, string $text, int $maxWidth = 80): void
    {
        // TODO: With box around all sides



    }

}
