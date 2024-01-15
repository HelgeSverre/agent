<?php

namespace App\Commands;

use App\Agent\Agent;
use App\Agent\CallbackHandler;
use App\TextUtils;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use Throwable;

use function Laravel\Prompts\confirm;

class RunEventDrivenAgent extends Command
{
    protected $signature = 'run {task?}';

    public function handle(): void
    {
        $task = $this->argument('task') ?: 'Build me a personal hompage using tailwindcss and save the html file in website.html, my current website is https://helgesver.re use the information you find on there as inspiration for the new website';

        if (! $task) {
            $task = $this->ask('What do you want to do?');
        }

        $this->comment("Task: \n".wordwrap($task, 80));

        $agent = new Agent(
            tools: $this->tools(),
            goal: 'Respond to the human as helpfully and accurately as possible. The human will ask you to do things, and you should do them.',
            callbacks: $this->callbackHandler()
        );

        $finalResponse = $agent->run($task);

        $this->warn("Final response: {$finalResponse}");

    }

    protected function tools(): array
    {
        return [
            'write_file' => [
                'description' => 'write a file to the local file system',
                'arguments' => [
                    'file_name' => [
                        'type' => 'string',
                        'description' => 'the name of the file to write (filename only, no path)',
                    ],
                    'file_content' => [
                        'type' => 'string',
                        'description' => 'the content of the file to write',
                    ],
                ],
            ],
            'read_file' => [
                'description' => 'read a file from the local file system',
                'arguments' => [
                    'file_name' => [
                        'type' => 'string',
                        'description' => 'the name of the file to read (filename only, no path)',
                    ],
                ],
            ],
            'search_web' => [
                'description' => 'search the web for a specific search term',
                'arguments' => [
                    'search_term' => [
                        'type' => 'string',
                        'description' => 'the search term to search for',
                    ],
                    'num_results' => [
                        'type' => 'int',
                        'description' => 'the number of results to return',
                    ],
                ],
            ],
            'browse_website' => [
                'description' => 'Get the contents of a website',
                'arguments' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The website url to retrieve',
                    ],
                ],
            ],
            'run_command' => [
                'description' => 'run a command on the terminal and get the output, useful for running command line tools or listing files etc',
                'arguments' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'the command to run',
                    ],
                ],
            ],
        ];
    }

    protected function callbackHandler(): CallbackHandler
    {
        return new CallbackHandler(
            onIteration: fn ($iteration, $maxIterations) => $this->comment("Iteration {$iteration} of {$maxIterations}"),
            onThought: fn ($thought) => $this->warn("Thought: $thought"),
            onObservation: fn ($observation) => $this->comment("Observation: {$observation}"),
            onAction: function ($action, $args) {
                $this->comment("Action: $action");
                if ($args) {
                    if (is_array($args)) {
                        foreach ($args as $key => $value) {
                            $text = is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value;
                            $this->comment("Action Input: {$key} => {$text}");
                        }
                    } else {
                        $this->comment("Action Input: {$args}");
                    }
                }

                confirm('Allow this action?');

                try {
                    if ($action === 'read_file') {
                        if (file_exists('./output') === false) {
                            mkdir('./output');
                        }

                        return 'File contents: '.file_get_contents('./output/'.$args['file_name']);
                    }

                    if ($action === 'write_file') {
                        if (file_exists('./output') === false) {
                            mkdir('./output');
                        }
                        file_put_contents('./output/'.$args['file_name'], $args['file_content']);

                        return 'File written.';
                    }

                    if ($action === 'search_web') {
                        return $this->runSearchTool($args);
                    }

                    if ($action === 'browse_website') {

                        $response = Http::get($args['url'] ?? $args);

                        if ($response->failed()) {
                            return 'Could not retrieve website contents for url: '.$args['url'].' - '.$response->status().' - '.$response->body();
                        }

                        $text = TextUtils::cleanHtml($response->body());

                        return "Website contents: \n\n{$text}";
                    }

                    // TODO: This is dangerous, but it's just a demo, so...
                    if ($action === 'run_command') {
                        return $this->runTerminalTool($args);
                    }

                    return "No tool found for action: {$action}, try another tool.";

                } catch (Throwable $th) {
                    return 'The tool was provided with invalid or malformed input.';
                }

            },
            onFinalAnswer: fn ($answer) => $this->info("Final answer: {$answer}"),

        );
    }

    protected function runSearchTool($args): string
    {
        /** @noinspection LaravelFunctionsInspection */
        $response = Http::withHeader('X-Subscription-Token', env('BRAVE_API_KEY'))
            ->acceptJson()
            ->asJson()
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $args['search_term'],
                'count' => $args['num_results'],
            ]);

        if ($response->status() == 422) {
            return 'The tool returned an error, the input arguments might be wrong';
        }

        if ($response->failed()) {
            return 'The tool returned an error.';
        }

        return $response
            ->collect('web.results')
            ->map(fn ($result) => [
                'title' => $result['title'],
                'url' => $result['url'],
                'description' => strip_tags($result['description']),
            ])
            ->toJson(JSON_PRETTY_PRINT);
    }

    protected function runTerminalTool($args): string
    {
        $command = $args['command'];
        $process = new Process(explode(' ', $command));
        $process->run();

        return $process->getOutput();
    }
}
