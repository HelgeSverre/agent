# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based AI Agent Framework built with Laravel Zero v10.3. It enables building CLI applications that use
OpenAI's function calling API to execute tasks through natural language commands.

## Key Commands

### Development

```bash
# Install dependencies
composer install

# Run the agent with a task
php agent run "your task description"

# Run tests
composer test

# Format code (uses Laravel Pint)
composer format

# Build PHAR executable (if needed)
box compile
```

### Testing

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/pest tests/Unit/Agent/AgentTest.php

# Run with coverage
vendor/bin/pest --coverage
```

## Architecture Overview

### Core Components

1. **Agent System** (`app/Agent/`)
    - `Agent.php`: Main orchestrator that runs the ReAct loop, manages tools, and coordinates execution
    - `LLM.php`: OpenAI API integration, handles function calling and JSON responses
    - `Tool.php`: Abstract base class for all tools, uses reflection for parameter extraction
    - `Hooks.php`: Event system for monitoring agent behavior and UI feedback

2. **Tool Framework**
    - Tools extend `Tool` abstract class
    - Tool names must match pattern `^[a-zA-Z0-9_-]+$` (no spaces)
    - Parameters are automatically extracted via reflection
    - Use `#[Description]` attribute for parameter documentation
    - File operations are restricted to the `output/` directory by default

3. **Execution Flow**
   ```
   CLI Command → Agent::run() → Loop:
     1. Agent asks LLM for next action
     2. LLM selects tool and arguments
     3. Tool executes and returns observation
     4. Context updated with result
     5. Check if task complete
   → Final answer or max iterations
   ```

## Creating New Tools

Tools must follow this pattern:

```php
<?php

namespace App\Tools;

use App\Agent\Tool\Tool;
use App\Agent\Tool\Attributes\AsTool;
use App\Agent\Tool\Attributes\Description;

#[AsTool(
    name: 'your_tool_name',  // Must match ^[a-zA-Z0-9_-]+$
    description: 'What this tool does'
)]
class YourNewTool extends Tool
{
    public function run(
        #[Description('Describe this parameter')]
        string $requiredParam,
        
        #[Description('Optional parameter description')]
        ?string $optionalParam = null
    ): string {
        // Tool implementation
        // Return string observation for the agent
        
        return "Tool execution result";
    }
}
```

Then register in `RunAgent.php`:

```php
$tools = [
    // ... existing tools
    new YourNewTool(),
];
```

## Important Conventions

1. **Error Handling**: Tools should return error messages as strings rather than throwing exceptions when possible
2. **File Paths**: Always validate file paths and use the configured base directory
3. **Tool Naming**: Use snake_case for tool names, must match regex pattern ^[a-zA-Z0-9_-]+$
4. **Type Safety**: Use PHP 8 type hints and nullable types appropriately
5. **Attributes**: Use `#[AsTool]` for tool metadata and `#[Description]` for parameter documentation

## Hook System

The framework triggers these events during execution:

- `start`: Task begins
- `iteration`: Each loop iteration
- `thought`: LLM reasoning process
- `action`: Tool selection
- `observation`: Tool output
- `evaluation`: Task completion check
- `final_answer`: Task completed
- `max_iteration`: Hit iteration limit

Use hooks in commands for UI feedback or monitoring.

## Configuration

Key environment variables:

- `OPENAI_API_KEY`: Required for LLM access
- `OPENAI_REQUEST_TIMEOUT`: API timeout (default: 30)
- `BRAVE_API_KEY`: For web search functionality

Configuration files in `config/`:

- `openai.php`: Model settings and API configuration
- `app.php`: Application settings
- `imap.php`: Email integration settings

## Common Patterns

### Adding Web Capabilities

The framework includes `SearchWebTool` and `BrowseWebsiteTool` using Brave Search API and web scraping.

### File Operations

`ReadFileTool` and `WriteFileTool` handle file I/O with path validation. Files are restricted to the `output/`
directory.

### External Commands

`RunCommandTool` executes shell commands - use with caution and proper validation.

### Email Integration

Email tools in `app/Tools/EmailToolkit/` provide IMAP-based email functionality.

## Testing Guidelines

- Unit tests go in `tests/Unit/`
- Feature tests go in `tests/Feature/`
- Test tools by mocking the LLM responses
- Use Pest PHP's describe/it syntax
- Architecture tests in `tests/ArchTest.php` ensure code standards

## Performance Considerations

- The agent maintains conversation history but trims to last 5 steps for token efficiency
- Each iteration makes an API call, so complex tasks may require multiple requests
- Tools should return concise observations to minimize token usage
- Default model is `gpt-4-mini` for cost efficiency
