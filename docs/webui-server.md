# WebUI Server Implementation

This document describes the WebSocket server implementation for real-time communication between the webui.html frontend and the PHP Agent framework.

## Overview

The WebUI server provides:

- Real-time bidirectional communication via WebSocket
- Live streaming of agent activity and thoughts
- Task execution through the web interface
- Session management and connection handling
- Proper error handling and recovery

## Architecture

### Components

1. **WebUIServer Command** (`app/Commands/WebUIServer.php`)
    - Main entry point for starting the WebSocket server
    - Integrates with Laravel Zero command structure
    - Configurable host and port

2. **WebSocketHandler** (`app/WebUI/WebSocketHandler.php`)
    - Handles WebSocket connections and messages
    - Manages client connections and sessions
    - Coordinates with MessageHandler for request processing

3. **MessageHandler** (`app/WebUI/Messages/MessageHandler.php`)
    - Processes different message types from clients
    - Executes tasks through the Agent class
    - Sets up hooks for real-time activity streaming

4. **WebUISessionManager** (`app/WebUI/Session/WebUISessionManager.php`)
    - Manages WebSocket sessions
    - Tracks connection state and task history
    - Provides session cleanup and maintenance

## Usage

### Starting the Server

```bash
# Start with default settings (127.0.0.1:8080)
php agent webui:server

# Specify custom host and port
php agent webui:server --host=0.0.0.0 --port=9000

# Or use the shorthand via run command
php agent run --web
```

### Frontend Connection

The `webui.html` file automatically connects to `ws://127.0.0.1:8080` and provides:

- Command input interface
- Real-time activity streaming with tool-specific icons
- Streaming tool calls with inline results
- Task queue sidebar with elapsed time and nested tool steps
- Connection status monitoring

## Message Protocol

### Client to Server Messages

#### Execute Task

```json
{
    "type": "execute_task",
    "task": "Create a PHP function to validate emails",
    "options": {
        "parallel": false,
        "maxIterations": 20,
        "saveSession": true
    }
}
```

#### Get Status

```json
{
    "type": "get_status"
}
```

#### Cancel Task

```json
{
    "type": "cancel_task",
    "taskId": "task_abc123"
}
```

#### Ping

```json
{
    "type": "ping"
}
```

### Server to Client Messages

#### Welcome

```json
{
    "type": "welcome",
    "sessionId": "webui_abc123def456",
    "timestamp": 1640995200,
    "message": "Connected to Agent WebUI"
}
```

#### Task Started

```json
{
    "type": "task_started",
    "taskId": "task_xyz789",
    "task": "Create a PHP function to validate emails",
    "sessionId": "webui_abc123def456",
    "timestamp": 1640995200
}
```

#### Task Completed

```json
{
    "type": "task_completed",
    "taskId": "task_xyz789",
    "result": "I've created a comprehensive email validation function...",
    "sessionId": "webui_abc123def456",
    "timestamp": 1640995260
}
```

#### Activity Stream

```json
{
    "type": "activity",
    "taskId": "task_xyz789",
    "activity_type": "action",
    "action": "write_file",
    "action_input": {
        "filename": "email_validator.php",
        "content": "<?php..."
    },
    "timestamp": 1640995230
}
```

#### Status Updates

```json
{
    "type": "status",
    "status": "processing",
    "operation": "Analyzing request...",
    "taskId": "task_xyz789"
}
```

## Activity Types

The server streams various activity types in real-time:

- **task_start**: Task execution begins
- **action**: Tool/action execution with tool name and input parameters
- **thought**: Agent reasoning/thoughts
- **observation**: Tool execution results
- **tool_execution**: Tool being executed (deduplicated with action in frontend)
- **tool_success**: Tool completed successfully, includes execution time in ms
- **tool_error**: Tool execution failed, includes error message
- **final_answer**: Task completion response
- **parallel_start**: Parallel execution begins
- **parallel_complete**: Parallel execution finished
- **context_compressed**: Context management events

## Configuration

### Dependencies

Add to `composer.json`:

```json
{
    "require": {
        "ratchet/pawl": "^0.4",
        "ratchet/rfc6455": "^0.3",
        "ratchet/websocket-server": "^0.4",
        "react/http": "^1.9",
        "react/socket": "^1.15"
    }
}
```

### Server Options

- `--host`: Bind address (default: 127.0.0.1)
- `--port`: Port number (default: 8080)

### Frontend Configuration

Update WebSocket URL in `webui.html` if using custom host/port:

```javascript
const wsUrl = `${protocol}//YOUR_HOST:YOUR_PORT`;
```

## Features

### Real-time Activity Streaming

- Live display of agent thoughts and actions
- Tool execution progress
- Context management events
- Error handling and recovery

### Session Management

- Unique session IDs for each connection
- Task history tracking
- Automatic cleanup of expired sessions
- Connection state management

### Error Handling

- Graceful error recovery
- Automatic reconnection attempts
- Circuit breaker integration
- Comprehensive error logging

### Connection Management

- Multiple concurrent connections
- Connection heartbeat/ping
- Proper cleanup on disconnect
- Resource management

## Integration Points

### Agent Class Integration

The WebSocket server integrates seamlessly with the existing Agent class:

- Uses same tool array and configuration
- Leverages existing Hooks system
- Maintains session compatibility
- Supports all agent features (parallel execution, planning, etc.)

### Tool Execution

All existing tools work through the WebSocket interface:

- ReadFileTool
- WriteFileTool
- SearchWebTool
- BrowseWebsiteTool
- RunCommandTool
- SpeakTool

### Hooks System

Comprehensive hook integration provides real-time updates for:

- Task lifecycle events
- Tool executions
- Agent reasoning
- Context management
- Error conditions

## Security Considerations

- Server binds to localhost by default
- No authentication implemented (suitable for development)
- For production use, consider adding:
    - Authentication/authorization
    - Rate limiting
    - Input validation
    - CORS policy
    - SSL/TLS support

## Performance

- Efficient message serialization with JSON
- Minimal memory overhead per connection
- Automatic session cleanup
- Connection pooling and management
- Real-time streaming without polling

## Development

### Testing

```bash
# Start the server
php agent webui:server

# Open webui.html in browser
# Test various commands and observe real-time activity
```

### Debugging

- Server logs connections and errors to console
- Browser developer tools show WebSocket communication
- Laravel logs available for detailed debugging

## Future Enhancements

- Authentication and authorization
- Multi-user support
- Chat history persistence
- File upload/download support
- Custom tool interfaces
- Performance metrics dashboard
- Plugin system for custom message types
