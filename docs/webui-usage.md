# WebUI Server Usage Guide

## Quick Start

1. **Start the WebSocket server:**

    ```bash
    # Default: 127.0.0.1:8080
    php agent webui:server

    # Custom host/port
    php agent webui:server --host=0.0.0.0 --port=9000

    # Alternative via run command
    php agent run --web
    ```

2. **Open the WebUI:**
    - Open `webui.html` in your web browser
    - The UI will automatically connect to `ws://127.0.0.1:8080`

3. **Start interacting:**
    - Type commands in the input field
    - Watch real-time agent activity and responses
    - View task progress in the sidebar

## Features

### Real-time Communication

- **Bidirectional WebSocket connection** between browser and PHP agent
- **Live streaming** of agent thoughts, actions, and tool executions
- **Automatic reconnection** with retry logic
- **Connection status** monitoring and feedback

### Task Management

- **Task execution** through web interface
- **Progress tracking** with visual indicators
- **Task history** and status management
- **Session persistence** across connections

### Activity Monitoring

- **Real-time activity stream** showing:
    - Agent thoughts and reasoning
    - Tool executions with parameters
    - Observations and results
    - Status updates and errors
    - Parallel execution events

### User Interface

- **Modern terminal-style** dark theme interface
- **Activity timeline** with timestamps and tool-specific icons (matching CLI output)
- **Streaming tool calls** with inline parameters and slide-in results
- **Task queue** sidebar with elapsed time and nested tool steps
- **Thinking display** with `◈` icon shown expanded
- **Final answer** with `✓` icon and bold label
- **Responsive design** for different screen sizes

## Command Examples

### Basic Task Execution

```
Create a PHP function to validate email addresses
```

### File Operations

```
Read the file README.md and summarize it
Write a test file for the email validator
```

### Web Research

```
Search for Laravel best practices and create a summary
Browse the Laravel documentation for validation
```

### Development Tasks

```
Create a REST API endpoint for user management
Generate unit tests for the User model
Run the test suite and fix any failing tests
```

## Architecture Overview

### Server Components

- **WebUIServer** - Laravel Zero command that starts the server
- **ReactWebSocketServer** - Handles WebSocket connections using ReactPHP
- **MessageHandler** - Processes different message types and executes tasks
- **WebUISessionManager** - Manages WebSocket sessions and state

### Message Flow

1. **Client connects** → Server performs WebSocket handshake
2. **Client sends task** → Server creates Agent instance and executes
3. **Agent hooks trigger** → Server streams activity to client
4. **Task completes** → Server sends final result to client

### Integration Points

- **Existing Agent class** - Full compatibility with all agent features
- **Tool execution** - All existing tools work through WebSocket
- **Hook system** - Real-time streaming via existing hook architecture
- **Session management** - Compatible with existing session system

## Advanced Usage

### Parallel Execution

Tasks that benefit from parallel execution will automatically use it:

```
Analyze three different PHP frameworks and compare them
```

### Session Management

Sessions are automatically created and managed:

- Each WebSocket connection gets a unique session ID
- Task history is maintained per session
- Sessions expire after inactivity

### Error Handling

- Connection errors trigger automatic reconnection
- Task execution errors are displayed in real-time
- Circuit breaker protection prevents infinite loops

## Configuration

### Server Settings

```bash
# Bind to all interfaces (use with caution)
php agent webui:server --host=0.0.0.0

# Use different port
php agent webui:server --port=9000

# Verbose logging
php agent webui:server -v
```

### Frontend Settings

To change WebSocket endpoint, edit `webui.html`:

```javascript
const wsUrl = `${protocol}//YOUR_HOST:YOUR_PORT`;
```

## Security Considerations

### Development Use

- Server binds to localhost by default
- No authentication implemented
- Suitable for local development only

### Production Considerations

For production deployment, consider adding:

- Authentication and authorization
- Rate limiting and input validation
- CORS policy configuration
- SSL/TLS support
- Firewall rules

## Troubleshooting

### Connection Issues

1. **Server not starting:**
    - Check if port is already in use
    - Verify ReactPHP dependencies are installed
    - Check PHP error logs

2. **WebSocket connection fails:**
    - Ensure server is running on correct host/port
    - Check browser console for WebSocket errors
    - Verify firewall allows WebSocket connections

3. **Tasks not executing:**
    - Check server console for error messages
    - Verify agent has necessary permissions
    - Review tool-specific error messages

### Performance Issues

- Large tasks may take time to complete
- Monitor memory usage during long-running tasks
- Use parallel execution for independent operations

## Development

### Adding Custom Message Types

1. Add handler in `MessageHandler::handleMessage()`
2. Update frontend `handleWebSocketMessage()` function
3. Document new message protocol

### Custom Activity Types

1. Add activity type in agent hooks
2. Update frontend `handleActivityMessage()` function
3. Add appropriate UI styling and icons

### Testing

```bash
# Run feature tests
php artisan test --filter WebUIServerTest

# Test WebSocket connection manually
wscat -c ws://127.0.0.1:8080
```

## Dependencies

### PHP Packages

- `react/socket` - ReactPHP socket server
- `react/http` - HTTP server components
- `ratchet/rfc6455` - WebSocket protocol implementation

### Frontend

- Native WebSocket API (no external dependencies)
- Modern CSS features (CSS Grid, Flexbox, CSS Variables)
- ES6+ JavaScript features

## Support

For issues and questions:

- Review server console output for errors
- Check browser developer tools for WebSocket issues
- Consult Laravel Zero documentation for command issues
- Review ReactPHP documentation for server issues
