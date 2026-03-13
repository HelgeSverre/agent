<?php

namespace App\WebUI\Messages;

use App\Agent\Agent;
use App\Agent\Hooks;
use App\WebUI\WebSocketHandler;
use Exception;
use Ratchet\ConnectionInterface;

class MessageHandler
{
    protected array $tools;

    protected array $activeTasks = [];

    public function __construct(array $tools)
    {
        $this->tools = $tools;
    }

    public function handleMessage(
        array $data,
        string $sessionId,
        ConnectionInterface $connection,
        WebSocketHandler $handler
    ): void {
        $type = $data['type'] ?? 'unknown';

        switch ($type) {
            case 'execute_task':
                $this->handleExecuteTask($data, $sessionId, $connection, $handler);
                break;

            case 'ping':
                $this->handlePing($connection, $handler);
                break;

            case 'get_status':
                $this->handleGetStatus($sessionId, $connection, $handler);
                break;

            case 'cancel_task':
                $this->handleCancelTask($data, $sessionId, $connection, $handler);
                break;

            case 'get_context':
                $this->handleGetContext($sessionId, $connection, $handler);
                break;

            default:
                $handler->sendError($connection, "Unknown message type: {$type}");
        }
    }

    protected function handleExecuteTask(
        array $data,
        string $sessionId,
        ConnectionInterface $connection,
        WebSocketHandler $handler
    ): void {
        $task = $data['task'] ?? '';
        $options = $data['options'] ?? [];

        if (empty($task)) {
            $handler->sendError($connection, 'Task cannot be empty');

            return;
        }

        // Check if there's already an active task for this session
        if (isset($this->activeTasks[$sessionId])) {
            $handler->sendError($connection, 'Task already running. Cancel current task first.');

            return;
        }

        // Create task ID
        $taskId = uniqid('task_');
        $this->activeTasks[$sessionId] = $taskId;

        // Send task started response
        $handler->sendToConnection($connection, [
            'type' => 'task_started',
            'taskId' => $taskId,
            'task' => $task,
            'sessionId' => $sessionId,
            'timestamp' => time(),
        ]);

        // Execute task in background (simulate async)
        $this->executeTaskAsync($task, $taskId, $sessionId, $connection, $handler, $options);
    }

    protected function executeTaskAsync(
        string $task,
        string $taskId,
        string $sessionId,
        ConnectionInterface $connection,
        WebSocketHandler $handler,
        array $options
    ): void {
        try {
            // Create hooks to capture agent activity
            $hooks = new Hooks;
            $this->setupAgentHooks($hooks, $connection, $handler, $taskId, $sessionId);

            $maxIterations = $options['maxIterations'] ?? 20;
            $parallelEnabled = $options['parallel'] ?? false;

            // Try to restore agent from session to maintain conversation history
            $agent = Agent::fromSession($sessionId, $this->tools, $hooks, $maxIterations, $parallelEnabled);

            if ($agent) {
                // Reset task-specific state but keep conversation history
                $agent->resetForNextTask();
            } else {
                // Create fresh agent for new sessions
                $agent = new Agent(
                    tools: $this->tools,
                    goal: 'Current date: '.date('Y-m-d')."\n".
                        'Respond to the human as helpfully and accurately as possible. '.
                        'The human will ask you to do things, and you should do them.',
                    maxIterations: $maxIterations,
                    hooks: $hooks,
                    parallelEnabled: $parallelEnabled
                );
            }

            // Always enable session persistence to maintain conversation memory
            $agent->enableSession($sessionId);

            // Send status update
            $handler->sendToConnection($connection, [
                'type' => 'status',
                'status' => 'processing',
                'operation' => 'Analyzing request...',
                'taskId' => $taskId,
            ]);

            // Run the agent
            $result = $agent->run($task);

            // Send completion
            $handler->sendToConnection($connection, [
                'type' => 'task_completed',
                'taskId' => $taskId,
                'result' => $result,
                'sessionId' => $sessionId,
                'timestamp' => time(),
            ]);

            // Update status
            $handler->sendToConnection($connection, [
                'type' => 'status',
                'status' => 'ready',
                'operation' => 'idle',
            ]);

        } catch (Exception $e) {
            // Send error
            $handler->sendToConnection($connection, [
                'type' => 'task_error',
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'sessionId' => $sessionId,
                'timestamp' => time(),
            ]);

            $handler->getCommand()->error("Task execution error: {$e->getMessage()}");
        } finally {
            // Clean up
            unset($this->activeTasks[$sessionId]);
        }
    }

    protected function setupAgentHooks(
        Hooks $hooks,
        ConnectionInterface $connection,
        WebSocketHandler $handler,
        string $taskId,
        string $sessionId
    ): void {
        // Task start
        $hooks->on('start', function ($task) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'task_start',
                'content' => $task,
                'timestamp' => time(),
            ]);
        });

        // Iteration tracking
        $hooks->on('iteration', function ($iteration) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'progress',
                'taskId' => $taskId,
                'iteration' => $iteration,
                'timestamp' => time(),
            ]);
        });

        // Action execution
        $hooks->on('action', function ($action) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'action',
                'action' => $action['action'],
                'action_input' => $action['action_input'] ?? [],
                'timestamp' => time(),
            ]);
        });

        // Thoughts
        $hooks->on('thought', function ($thought) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'thought',
                'content' => $thought,
                'timestamp' => time(),
            ]);
        });

        // Observations
        $hooks->on('observation', function ($observation) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'observation',
                'content' => $observation,
                'timestamp' => time(),
            ]);
        });

        // Tool execution
        $hooks->on('tool_execution', function ($toolName, $toolInput) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'tool_execution',
                'tool' => $toolName,
                'input' => $toolInput,
                'timestamp' => time(),
            ]);
        });

        // Final answer
        $hooks->on('final_answer', function ($answer) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'final_answer',
                'content' => $answer,
                'timestamp' => time(),
            ]);
        });

        // Parallel execution
        $hooks->on('parallel_execution_start', function ($count) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'parallel_start',
                'count' => $count,
                'timestamp' => time(),
            ]);
        });

        $hooks->on('parallel_execution_complete', function ($count) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'parallel_complete',
                'count' => $count,
                'timestamp' => time(),
            ]);
        });

        // Tool execution results
        $hooks->on('tool_execution_success', function ($toolName, $toolInput, $result, $time) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'tool_success',
                'tool' => $toolName,
                'executionTime' => round($time * 1000),
                'timestamp' => time(),
            ]);
        });

        $hooks->on('tool_execution_error', function ($toolName, $toolInput, $result, $time) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'tool_error',
                'tool' => $toolName,
                'error' => is_string($result) ? $result : json_encode($result),
                'timestamp' => time(),
            ]);
        });

        // Context management
        $hooks->on('compressed_context', function ($context) use ($connection, $handler, $taskId) {
            $handler->sendToConnection($connection, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity_type' => 'context_compressed',
                'content' => $context,
                'timestamp' => time(),
            ]);
        });
    }

    protected function handlePing(ConnectionInterface $connection, WebSocketHandler $handler): void
    {
        $handler->sendToConnection($connection, [
            'type' => 'pong',
            'timestamp' => time(),
        ]);
    }

    protected function handleGetStatus(
        string $sessionId,
        ConnectionInterface $connection,
        WebSocketHandler $handler
    ): void {
        $hasActiveTask = isset($this->activeTasks[$sessionId]);
        $taskId = $hasActiveTask ? $this->activeTasks[$sessionId] : null;

        $handler->sendToConnection($connection, [
            'type' => 'status_response',
            'status' => $hasActiveTask ? 'processing' : 'ready',
            'operation' => $hasActiveTask ? 'Running task' : 'idle',
            'activeTaskId' => $taskId,
            'timestamp' => time(),
        ]);
    }

    protected function handleCancelTask(
        array $data,
        string $sessionId,
        ConnectionInterface $connection,
        WebSocketHandler $handler
    ): void {
        $taskId = $data['taskId'] ?? null;

        if (! isset($this->activeTasks[$sessionId])) {
            $handler->sendError($connection, 'No active task to cancel');

            return;
        }

        if ($taskId && $this->activeTasks[$sessionId] !== $taskId) {
            $handler->sendError($connection, 'Task ID mismatch');

            return;
        }

        // For now, we'll just mark as cancelled
        // In a real implementation, you'd want to interrupt the agent execution
        unset($this->activeTasks[$sessionId]);

        $handler->sendToConnection($connection, [
            'type' => 'task_cancelled',
            'taskId' => $taskId,
            'sessionId' => $sessionId,
            'timestamp' => time(),
        ]);

        $handler->sendToConnection($connection, [
            'type' => 'status',
            'status' => 'ready',
            'operation' => 'idle',
        ]);
    }

    protected function handleGetContext(
        string $sessionId,
        ConnectionInterface $connection,
        WebSocketHandler $handler
    ): void {
        // Get current working directory and basic context
        $context = [
            'directory' => getcwd(),
            'session' => $sessionId,
            'tools' => array_map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ], $this->tools),
            'timestamp' => time(),
        ];

        $handler->sendToConnection($connection, [
            'type' => 'context_response',
            'context' => $context,
            'timestamp' => time(),
        ]);
    }
}
