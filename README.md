<p align="center"><img src="./art/header.png"></p>

# Agent - AI Agent Framework for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)
[![License](https://img.shields.io/github/license/helgesverre/agent.svg?style=flat-square)](https://github.com/helgesverre/agent/blob/main/LICENSE.md)

A Laravel Zero-based CLI application for building and running AI agents with tool calling capabilities using OpenAI's
function calling API.

> Note: This is mostly meant as a playground for my own experimentation, don't use this for anything serious, i will
> likely break your shit eventually.

## See it in action

<a href="https://share.cleanshot.com/kTZXyFnY"><img src="./art/thumb.png"></a>

Video Link: https://share.cleanshot.com/kTZXyFnY

## Installation

Clone the repository and install dependencies:

```bash
git clone https://github.com/helgesverre/agent.git
cd agent
composer install
```

## Configuration

Set your OpenAI API key in your `.env` file:

```env
OPENAI_API_KEY=your-api-key-here
```

## Key Features

- **Function Calling**: Native OpenAI function calling API integration
- **Error Recovery**: Graceful handling of failures with automatic retries
- **Enhanced Prompting**: Markdown-based prompts for better model comprehension
- **Context Management**: Automatic conversation history tracking
- **Minimal Terminal Display**: Clean, colored symbol-based output
- **Hook System**: Monitor and customize agent behavior in real-time
- **Built-in Tools**: Web search, website browsing, file I/O, and command execution

## Quick Start Guide

### Running the Agent CLI

```bash
# Run with a task
php agent run "Write a hello world message to a file"

# Interactive mode - will prompt for task
php agent run

# Enable text-to-speech for the final answer (macOS only)
php agent run "What is the weather today?" --speak
```

### More Examples

```bash
# Search for information
php agent run "Find the latest PHP 8.3 features and summarize them"

# File operations
php agent run "Create a markdown file with today's date and a todo list template"

# Web research
php agent run "Search for the top 3 PHP frameworks in 2024 and compare them"

# System information
php agent run "Check the current PHP version and list installed extensions"
```

### Basic Agent Usage

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

### CLI Output

The agent uses a minimal display with colored symbols:

- ◆ Task announcement (cyan)
- ◈ Agent thoughts (blue)
- ⬡ Web search (blue hexagon)
- ⬢ Browse website (green hexagon)
- ⬣ Read file (yellow hexagon)
- ⬤ Write file (magenta circle)
- ⬥ Run command (cyan diamond)
- ◉ Task evaluation (green circle)
- ✓ Final answer (green checkmark)

Example output:

```
◆ Task: find a simple recipe for pasta

⬡ search_web
  └─ [...results...]
⬢ browse_website www.example.com
◉ The user requested a simple pasta recipe...

✓ Answer: Here's a simple pasta recipe...
```

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

### Example: Adding a Database Query Tool

```php
class DatabaseQueryTool extends Tool
{
    protected string $name = 'query_database';
    protected string $description = 'Execute safe read-only database queries';
    
    public function run(
        #[Description('The SQL query to execute (SELECT only)')]
        string $query
    ): string {
        // Validate it's a SELECT query
        if (!str_starts_with(strtoupper(trim($query)), 'SELECT')) {
            return "Error: Only SELECT queries are allowed";
        }
        
        // Execute query...
        return json_encode($results);
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
// - 'start': When task begins
// - 'iteration': New step number
// - 'thought': Agent reasoning
// - 'action': Tool execution
// - 'observation': Tool results
// - 'evaluation': Progress check
// - 'final_answer': Task complete
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

- **SearchWebTool** (`search_web`): Search the web for information using DuckDuckGo
- **BrowseWebsiteTool** (`browse_website`): Extract text content from websites
- **ReadFileTool** (`read_file`): Read file contents from the output directory
- **WriteFileTool** (`write_file`): Create or update files in the output directory
- **RunCommandTool** (`run_command`): Execute system commands safely

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

### Model Configuration

The agent uses OpenAI's API. You can configure the model by setting the constant in `App\Agent\LLM`:

```php
// In App\Agent\LLM
const model = 'gpt-4o-mini'; // Default model
```

### FunctionCallBuilder

The `FunctionCallBuilder` class handles the interaction with OpenAI's function calling API:

```php
use App\Agent\LLM;

// The Agent class automatically uses function calling:
$result = LLM::functionCall($tools)->get($prompt);

// Result structure:
// [
//     'function_call' => [
//         'name' => 'tool_name',
//         'arguments' => [...]
//     ],
//     'thought' => 'reasoning...'
// ]
```

### Testing

The project includes comprehensive tests:

```bash
# Run all tests
composer test

# Run specific test suite
composer test -- --filter=AgentTest
```

## Limitations

- Cannot modify files outside the designated output directory
- Web browsing extracts text only (no JavaScript execution)
- Command execution is sandboxed for safety
- Context window limits long conversations
- No persistent memory between sessions

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
