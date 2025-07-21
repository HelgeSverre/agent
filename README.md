<p align="center"><img src="./art/header.png"></p>

# Agent - Library for building AI agents in PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)

A PHP library for building AI agents with tool calling capabilities using OpenAI's function calling API.

## See it in action

<a href="https://share.cleanshot.com/kTZXyFnY"><img src="./art/thumb.png"></a>

Video Link: https://share.cleanshot.com/kTZXyFnY

## Requirements

- PHP 8.1 or higher
- OpenAI API key
- Laravel framework (for facades and service providers)

## Installation

Install it via composer:

```bash
composer require helgesverre/agent
```

## Configuration

Set your OpenAI API key in your `.env` file:

```env
OPENAI_API_KEY=your-api-key-here
```

## Key Features

- **Function Calling**: Structured interaction with tools through JSON schema
- **Error Recovery**: Graceful handling of failures with automatic retries
- **Enhanced Prompting**: Markdown-based prompts for better model comprehension
- **Planning Capabilities**: Task decomposition and execution monitoring
- **Context Management**: Efficient memory system for extended conversations

### Coming Soon
- **Dynamic Model Selection**: Choose the right model for each task type
- **Multi-Agent Crews**: Collaborate across multiple specialized agents

## Quick Start Guide

### Basic Agent

```php
use App\Agent\Agent;
use App\Agent\Hooks;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;
use App\Tools\RunCommandTool;

// Create hooks for monitoring agent activity
$hooks = new Hooks();
$hooks->on('action', function($action) {
    echo "Executing: {$action['action']} with " . json_encode($action['action_input']) . "\n";
});
$hooks->on('observation', function($observation) {
    echo "Result: $observation\n";
});

// Initialize the agent with tools
$agent = new Agent(
    tools: [
        new ReadFileTool(),  // Reads from 'output' directory by default
        new WriteFileTool('./output'),
        new RunCommandTool(),
    ],
    goal: 'Help the user accomplish their task',
    hooks: $hooks,
    maxIterations: 10  // Prevent infinite loops
);

// Run the agent with a task
$result = $agent->run('Write a hello world message to a file called hello.txt');
echo "Final result: $result\n";
```


### Model Configuration

Currently, the agent uses OpenAI's API with a hardcoded model. The model can be changed by modifying the constant in `App\Agent\LLM`:

```php
// In App\Agent\LLM
const model = 'gpt-4.1-mini'; // Change this to your preferred model
```

> **Note**: Dynamic model selection is planned for a future release.

## Creating Custom Tools

Tools are the building blocks of agent capabilities. Here's how to create a custom tool:

### Important: Tool Naming Requirements
- Tool names must match the pattern `^[a-zA-Z0-9_-]+$`
- Use underscores or hyphens instead of spaces
- Examples: `get_weather`, `search_web`, `read-file`

```php
use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;

class WeatherTool extends Tool
{
    protected string $name = 'get_weather';  // REQUIRED: Use underscores, no spaces!
    protected string $description = 'Get the current weather for a location';

    public function run(
        #[Description('The city to get weather for')]
        string $city,
        
        #[Description('The country code (optional)')]
        ?string $country = null
    ): string {
        // Implementation to fetch weather data
        $location = $country ? "$city, $country" : $city;
        
        // Call weather API...
        $weatherData = $this->fetchWeatherData($location);
        
        return "The current weather in $location is: " . $weatherData;
    }
    
    private function fetchWeatherData(string $location): string {
        // Actual API implementation...
        return "sunny, 25°C";
    }
}
```

## Function Calling

The function calling system uses OpenAI's function calling feature to interact with tools:

```php
use App\Agent\Agent;
use App\Agent\LLM;
use App\Agent\FunctionCallBuilder;

// The Agent class automatically prepares tool schemas:
class Agent
{
    protected function prepareToolsSchema(): void
    {
        foreach ($this->tools as $tool) {
            $parameters = [];
            
            foreach ($tool->arguments() as $arg) {
                $parameters[$arg->name] = [
                    'type' => $this->mapPhpTypeToJsonSchema($arg->type),
                    'description' => $arg->description ?? '',
                ];
            }
            
            $this->toolsSchema[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $parameters,
                    'required' => array_values(array_map(
                        fn($arg) => $arg->name,
                        array_filter($tool->arguments(), fn($arg) => !$arg->nullable)
                    )),
                ],
            ];
        }
    }
}

// The LLM class provides a function calling interface:
$result = LLM::functionCall([
    'functions' => $toolsSchema,
    'final_answer' => [...]
])->get($prompt);
```

## Error Handling

The agent includes built-in error handling:

```php
use App\Agent\Agent;
use App\Agent\Hooks;

$hooks = new Hooks();

// Monitor errors through hooks
$hooks->on('observation', function($observation) {
    if (str_contains($observation, 'error:')) {
        error_log("Agent encountered error: $observation");
    }
});

$agent = new Agent(
    tools: [...],
    hooks: $hooks
);

// The agent will automatically:
// - Handle API errors gracefully
// - Retry failed tool executions
// - Provide error feedback in observations
// - Continue trying alternative approaches
```

## Context Management

The context management system helps track conversation history:

```php
use App\Agent\Agent;
use App\Agent\ContextManager;
use App\Agent\Hooks;

$hooks = new Hooks();

// The Agent class automatically manages intermediate steps
$agent = new Agent(
    tools: [...],
    goal: 'Help with tasks',
    hooks: $hooks
);

// The agent tracks steps internally:
// - Thoughts
// - Actions taken
// - Tool executions
// - Observations

// Context is automatically trimmed to last 5 steps to manage token usage
```

## Multi-Agent Collaboration (Coming Soon)

> **Note**: Multi-agent crew functionality is planned for a future release. This section shows the intended API.

The framework will support multi-agent crews for complex task collaboration:

```php
// PLANNED FEATURE - NOT YET IMPLEMENTED
use App\Agent\Agent;
use App\Agent\Crew\Crew;

// Create specialist agents
$researchAgent = new Agent(
    tools: [new SearchWebTool(), new BrowseWebsiteTool()],
    goal: 'Research information thoroughly and accurately'
);

$writerAgent = new Agent(
    tools: [new WriteFileTool(), new ReadFileTool()],
    goal: 'Create well-organized, comprehensive documents'
);

// Define tasks
$tasks = [
    new ResearchTask('Find current information about AI agents'),
    new CompilationTask('Create a comprehensive report'),
];

// Create the crew
$crew = new Crew(
    tasks: $tasks,
    agents: [$researchAgent, $writerAgent],
);

// Execute all tasks in sequence
$result = $crew->executeTasks();
```


## Hooks System

The hooks system allows monitoring and customizing agent behavior:

```php
use App\Agent\Hooks;

$hooks = new Hooks();

// Listen for specific events
$hooks->on('thought', function($thought) {
    echo "Agent is thinking: $thought\n";
});

$hooks->on('tool_execution', function($toolName, $args) {
    echo "Executing $toolName with: " . json_encode($args) . "\n";
});

// Available hook events:
// - 'thought': When the agent has a thought
// - 'prompt': Before sending prompt to LLM
// - 'action': When executing a tool
// - 'observation': Tool execution results
// - 'evaluation': Task completion evaluation
// - 'final_answer': When task is complete
```

## Task Evaluation

The agent evaluates task completion before providing final answers:

```php
use App\Agent\Agent;
use App\Agent\Hooks;

$hooks = new Hooks();
$hooks->on('evaluation', function($eval) {
    if ($eval) {
        echo "Task evaluation: " . ($eval['feedback'] ?? 'No feedback') . "\n";
        if (isset($eval['status'])) {
            echo "Status: " . $eval['status'] . "\n";
        }
    }
});

$agent = new Agent(
    tools: [...],
    goal: 'Complete tasks thoroughly',
    hooks: $hooks,
);

// The agent will:
// 1. Execute tools to work on the task
// 2. Evaluate if the task is complete
// 3. Provide a final answer only when satisfied
$result = $agent->run('Create a summary of recent tech news');
```

## Available Tools

The framework includes several built-in tools:

- **SearchWebTool** (`search_web`): Search the web for information
- **BrowseWebsiteTool** (`browse_website`): Navigate and extract content from websites
- **ReadFileTool** (`read_file`): Read file contents
- **WriteFileTool** (`write_file`): Create or update files
- **RunCommandTool** (`run_command`): Execute system commands
- **EmailToolkit**: Send, read, and manage email communications (if enabled)

## Advanced Configuration

### Customizing Prompt Templates

You can extend the `Prompt` class to customize how prompts are generated:

```php
use App\Agent\Prompt;

class CustomPrompt extends Prompt
{
    public function decideNextStep(): string
    {
        return $this->combine([
            "# Custom Agent Framework",
            "## Task Definition",
            $this->task,
            // Additional custom sections...
            $this->prepareTools(),
            $this->prepareContext(),
        ]);
    }
}

// To use a custom prompt, you would need to extend the Agent class
// and override the decideNextStep method to use your custom prompt
```

### FunctionCallBuilder

The `FunctionCallBuilder` class handles the interaction with OpenAI's function calling API:

```php
use App\Agent\LLM;
use App\Agent\FunctionCallBuilder;

// Create a function call builder with tool schemas
$builder = LLM::functionCall([
    'functions' => $toolSchemas,
    'final_answer' => [
        'name' => 'final_answer',
        'description' => 'Complete the task and provide a final answer',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'answer' => [
                    'type' => 'string',
                    'description' => 'The final answer or response to the task',
                ],
            ],
            'required' => ['answer'],
        ],
    ],
]);

// Execute with a prompt
$result = $builder->get($prompt);

// Result structure:
// [
//     'function_call' => [
//         'name' => 'tool_name',
//         'arguments' => [...]
//     ],
//     'thought' => 'reasoning...'
// ]
```


## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
