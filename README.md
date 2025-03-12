<p align="center"><img src="./art/header.png"></p>

# Agent - Library for building AI agents in PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)

Proof of concept AI Agent using the [Brain](https://github.com/helgesverre/brain) package.

## See it in action

<a href="https://share.cleanshot.com/kTZXyFnY"><img src="./art/thumb.png"></a>

Video Link: https://share.cleanshot.com/kTZXyFnY

## Installation

Install it via composer:

```bash
composer require helgesverre/agent
```

## Key Features

- **Function Calling**: Structured interaction with tools through JSON schema
- **Error Recovery**: Graceful handling of failures with automatic retries
- **Enhanced Prompting**: Markdown-based prompts for better model comprehension
- **Planning Capabilities**: Task decomposition and execution monitoring
- **Dynamic Model Selection**: Choose the right model for each task type
- **Context Management**: Efficient memory system for extended conversations

## Quick Start Guide

### Basic Agent

```php
use App\Agent\Agent;
use App\Agent\Hooks;
use App\Tools\SearchWebTool;
use App\Tools\WriteFileTool;

// Create hooks for monitoring agent activity
$hooks = new Hooks();
$hooks->on('thought', function($thought) {
    echo "Thinking: $thought\n";
});

// Initialize the agent with tools
$agent = new Agent(
    tools: [
        new SearchWebTool(),
        new WriteFileTool('./output'),
    ],
    goal: 'Help the user accomplish their task',
    hooks: $hooks
);

// Run the agent with a task
$result = $agent->run('Research the top 3 PHP frameworks in 2025 and write a comparison to a file');
```

### Using Planning Agent

```php
use App\Agent\PlanningAgent;
use App\Agent\Hooks;
use App\Tools\SearchWebTool;
use App\Tools\WriteFileTool;

$hooks = new Hooks();
$hooks->on('plan_created', function($plan) {
    echo "Created plan with " . count($plan) . " steps\n";
    foreach ($plan as $index => $step) {
        echo ($index + 1) . ". " . $step['description'] . "\n";
    }
});

$agent = new PlanningAgent(
    tools: [
        new SearchWebTool(),
        new WriteFileTool('./output'),
    ],
    goal: 'Help the user accomplish their task thoroughly',
    hooks: $hooks
);

$result = $agent->run('Research the top 3 PHP frameworks in 2025 and write a comparison to a file');
```

### Dynamic Model Selection

```php
use App\Agent\Agent;
use App\Agent\ModelSelector;
use App\Agent\Hooks;

$modelSelector = new ModelSelector();
$hooks = new Hooks();

$hooks->on('prompt', function($prompt) use ($modelSelector) {
    // Get the appropriate model for this prompt
    $brain = $modelSelector->getBrainInstance($prompt, 'auto');
    // Use $brain for your API call
});

$agent = new Agent(
    // ... other configurations
    hooks: $hooks
);
```

## Creating Custom Tools

Tools are the building blocks of agent capabilities. Here's how to create a custom tool:

```php
use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;

class WeatherTool extends Tool
{
    protected string $name = 'Get Weather';
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

The new function calling system provides a more structured way to interact with tools:

```php
use App\Agent\Agent;

class MyCustomAgent extends Agent
{
    protected function prepareToolsSchema(): void
    {
        foreach ($this->tools as $tool) {
            $parameters = [];
            
            foreach ($tool->arguments() as $arg) {
                $parameters[$arg->name] = [
                    'type' => $this->mapPhpTypeToJsonSchema($arg->type),
                    'description' => $arg->description ?? '',
                    'required' => !$arg->nullable,
                ];
            }
            
            $this->toolsSchema[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $parameters,
                    'required' => array_keys(array_filter($parameters, fn($param) => $param['required'])),
                ],
            ];
        }
    }
}
```

## Error Recovery System

The error recovery system helps handle failures gracefully:

```php
use App\Agent\ErrorRecovery;
use App\Agent\Hooks;

$hooks = new Hooks();
$errorRecovery = new ErrorRecovery($hooks);

// Using error recovery in a try/catch block
try {
    $result = $agent->run('Complex task that might fail');
} catch (\Exception $e) {
    $errorType = $this->determineErrorType($e);
    $recovery = $errorRecovery->handleError(
        $errorType,
        $e->getMessage(),
        function($recoveryMessage, $retryCount) use ($agent) {
            echo "Retry attempt $retryCount with message: $recoveryMessage\n";
            return $agent->run('Modified task based on error');
        }
    );
    
    $result = $recovery;
}
```

## Context Management

The context management system allows agents to maintain memory across interactions:

```php
use App\Agent\Agent;
use App\Agent\ContextManager;
use App\Agent\Hooks;

$hooks = new Hooks();
$contextManager = new ContextManager('agent-123', $hooks);

class MemoryEnabledAgent extends Agent
{
    protected ContextManager $contextManager;

    public function __construct(ContextManager $contextManager, /* other params */)
    {
        parent::__construct(/* other params */);
        $this->contextManager = $contextManager;
    }
    
    protected function decideNextStep(string $task)
    {
        // Get enriched context with memory
        $enrichedSteps = $this->contextManager->getContext($this->intermediateSteps);
        
        // Proceed with enhanced context
        $prompt = Prompt::make(
            task: $task,
            goal: $this->goal,
            tools: $this->tools,
            intermediateSteps: $enrichedSteps,
        )->decideNextStep();
        
        // Update memory periodically
        if ($this->currentIteration % 5 === 0) {
            $this->contextManager->updateMemory($this->intermediateSteps);
        }
        
        // Continue with normal flow...
    }
}
```

## Advanced Crew Integration

The enhanced agent framework supports multi-agent crews for complex task collaboration:

```php
use App\Agent\PlanningAgent;
use Mindwave\Mindwave\Crew\Crew;

// Create specialist agents
$researchAgent = new PlanningAgent(
    tools: [new SearchWebTool(), new BrowseWebsiteTool()],
    goal: 'Research information thoroughly and accurately'
);

$writerAgent = new PlanningAgent(
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

// Listen for all events
$hooks->onAny(function($event, ...$args) {
    echo "Event '$event' triggered with " . count($args) . " arguments\n";
    // Log to file, database, etc.
});
```

## Planning and Reflection

The planning system helps agents tackle complex tasks methodically:

```php
use App\Agent\PlanningAgent;
use App\Agent\Hooks;

$hooks = new Hooks();
$hooks->on('reflection', function($reflection) {
    echo "Agent reflection: " . $reflection['assessment'] . "\n";
    if ($reflection['update_plan']) {
        echo "Plan update needed: " . $reflection['feedback'] . "\n";
    }
});

$planningAgent = new PlanningAgent(
    tools: [...],
    goal: 'Accomplish task with careful planning and reflection',
    hooks: $hooks,
);

$result = $planningAgent->run('Design a database schema for a task management system');
```

## Available Tools

The framework includes several built-in tools:

- **SearchWebTool**: Search the web for information
- **BrowseWebsiteTool**: Navigate and extract content from websites
- **ReadFileTool**: Read file contents
- **WriteFileTool**: Create or update files
- **RunCommandTool**: Execute system commands
- **EmailToolkit**: Send, read, and manage email communications

## Advanced Configuration

### Customizing Prompt Templates

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

// Using the custom prompt in your agent
$agent = new Agent(
    // ... other configurations
);

// Override the prompt creation
$agent->setPromptClass(CustomPrompt::class);
```

### Configuring Model Parameters

```php
use App\Agent\ModelSelector;

$modelSelector = new ModelSelector();

// Add or update model configurations
$modelSelector->addModel('specialized', [
    'model' => 'your-specialized-model',
    'temp' => 0.3,
    'fast' => false,
    'max_tokens' => 4000,
]);

// Get a brain instance with custom configuration
$brain = $modelSelector->getBrainInstance($task, 'specialized');
```


## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
