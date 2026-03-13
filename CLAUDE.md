# PHP Agent Framework

## Project Overview

A PHP-based AI agent framework built with Laravel Zero. The agent uses an LLM to reason about tasks, select tools, and execute multi-step plans.

## Project Structure

- `app/Agent/` - Core agent logic (Agent, Hooks, Prompt, Planning, Session, Context)
- `app/Commands/` - CLI commands (`RunAgent`, `WebUIServer`, `ExecuteToolCommand`)
- `app/Tools/` - Tool implementations (ReadFile, WriteFile, RunCommand, SearchWeb, etc.)
- `app/WebSocket/` - WebSocket handler for agent interaction
- `app/WebUI/` - WebUI handler with session management and message routing
- `app/Http/` - HTTP/WebSocket hybrid handler
- `tests/` - Test suite

## Commands

- `php agent run "<task>"` - Run the agent with a task
- `php agent web --port=8080` - Start the WebUI server
- `php agent tool:execute <tool> <input>` - Execute a single tool

## Testing

- `vendor/bin/phpunit` - Run all tests
- `vendor/bin/phpunit --filter=ClassName` - Run specific test

## Code Style

- PSR-12 coding standard
- Type hints on parameters and return types
- Files under 500 lines
- Never hardcode secrets

## Important Notes

- Do what has been asked; nothing more, nothing less.
- NEVER create files unless they're absolutely necessary for achieving your goal.
- ALWAYS prefer editing an existing file to creating a new one.
- NEVER proactively create documentation files (\*.md) or README files. Only create documentation files if explicitly requested by the user.
- Never save working files, text/mds and tests to the root folder.
