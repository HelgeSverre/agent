<?php

namespace App\WebSocket;

use App\Agent\Agent;
use App\Agent\Hooks;
use Exception;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AgentWebSocketHandler implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;

    protected array $tools;

    protected ?OutputInterface $output;

    protected array $activeAgents = [];

    protected array $taskQueues = [];

    public function __construct(array $tools, ?OutputInterface $output = null)
    {
        $this->clients = new \SplObjectStorage;
        $this->tools = $tools;
        $this->output = $output;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $this->log("New connection: {$conn->resourceId}");

        // Send initial status
        $this->sendToConnection($conn, [
            'type' => 'connection',
            'status' => 'connected',
            'message' => 'Connected to Agent WebSocket server',
            'connectionId' => $conn->resourceId,
        ]);

        // Send current context
        $this->sendToConnection($conn, [
            'type' => 'context',
            'data' => [
                'directory' => getcwd(),
                'files' => $this->getRecentFiles(),
                'capabilities' => array_map(fn ($tool) => $tool->name(), $this->tools),
            ],
        ]);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->log("Message from {$from->resourceId}: {$msg}");

        try {
            $data = json_decode($msg, true);

            if (! $data || ! isset($data['type'])) {
                throw new Exception('Invalid message format');
            }

            switch ($data['type']) {
                case 'command':
                    $this->handleCommand($from, $data['command'] ?? '');
                    break;

                case 'status':
                    $this->sendStatus($from);
                    break;

                case 'cancel':
                    $this->cancelTask($from, $data['taskId'] ?? null);
                    break;

                default:
                    throw new Exception("Unknown message type: {$data['type']}");
            }
        } catch (Exception $e) {
            $this->sendError($from, $e->getMessage());
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        $this->log("Connection closed: {$conn->resourceId}");

        // Cancel any active agents for this connection
        if (isset($this->activeAgents[$conn->resourceId])) {
            // TODO: Implement agent cancellation
            unset($this->activeAgents[$conn->resourceId]);
        }
    }

    public function onError(ConnectionInterface $conn, Exception $e)
    {
        $this->log("Error on connection {$conn->resourceId}: {$e->getMessage()}");
        $this->sendError($conn, "Connection error: {$e->getMessage()}");
        $conn->close();
    }

    protected function handleCommand(ConnectionInterface $conn, string $command)
    {
        if (empty($command)) {
            $this->sendError($conn, 'Empty command');

            return;
        }

        // Create task ID
        $taskId = uniqid('task_');

        // Send acknowledgment
        $this->sendToConnection($conn, [
            'type' => 'task_started',
            'taskId' => $taskId,
            'command' => $command,
            'timestamp' => time(),
        ]);

        // Create agent with WebSocket hooks
        $hooks = $this->createHooksForConnection($conn, $taskId);

        $agent = new Agent(
            tools: $this->tools,
            goal: 'Current date: '.date('Y-m-d')."\n".
                  'Respond to the human as helpfully and accurately as possible.',
            maxIterations: 20,
            hooks: $hooks,
            parallelEnabled: true
        );

        // Store active agent
        $this->activeAgents[$conn->resourceId] = $agent;

        // Execute in background (in production, use proper async handling)
        try {
            $result = $agent->run($command);

            // Send completion
            $this->sendToConnection($conn, [
                'type' => 'task_completed',
                'taskId' => $taskId,
                'result' => $result,
                'timestamp' => time(),
            ]);
        } catch (Exception $e) {
            $this->sendToConnection($conn, [
                'type' => 'task_failed',
                'taskId' => $taskId,
                'error' => $e->getMessage(),
                'timestamp' => time(),
            ]);
        } finally {
            unset($this->activeAgents[$conn->resourceId]);
        }
    }

    protected function createHooksForConnection(ConnectionInterface $conn, string $taskId): Hooks
    {
        $hooks = new Hooks;

        // Hook for agent start
        $hooks->on('start', function ($task) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'start',
                'data' => ['task' => $task],
                'timestamp' => time(),
            ]);
        });

        // Hook for iterations
        $hooks->on('iteration', function ($iteration) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'iteration',
                'data' => ['iteration' => $iteration],
                'timestamp' => time(),
            ]);
        });

        // Hook for actions
        $hooks->on('action', function ($action) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'action',
                'data' => $action,
                'timestamp' => time(),
            ]);
        });

        // Hook for thoughts
        $hooks->on('thought', function ($thought) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'thought',
                'data' => ['thought' => $thought],
                'timestamp' => time(),
            ]);
        });

        // Hook for observations
        $hooks->on('observation', function ($observation) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'observation',
                'data' => ['observation' => $observation],
                'timestamp' => time(),
            ]);
        });

        // Hook for tool execution
        $hooks->on('tool_execution', function ($toolName, $toolInput) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'tool_execution',
                'data' => [
                    'tool' => $toolName,
                    'input' => $toolInput,
                ],
                'timestamp' => time(),
            ]);
        });

        // Hook for final answer
        $hooks->on('final_answer', function ($answer) use ($conn, $taskId) {
            $this->sendToConnection($conn, [
                'type' => 'activity',
                'taskId' => $taskId,
                'activity' => 'final_answer',
                'data' => ['answer' => $answer],
                'timestamp' => time(),
            ]);
        });

        return $hooks;
    }

    protected function sendStatus(ConnectionInterface $conn)
    {
        $status = [
            'type' => 'status',
            'activeAgents' => count($this->activeAgents),
            'connectedClients' => count($this->clients),
            'timestamp' => time(),
        ];

        $this->sendToConnection($conn, $status);
    }

    protected function cancelTask(ConnectionInterface $conn, ?string $taskId)
    {
        // TODO: Implement task cancellation
        $this->sendToConnection($conn, [
            'type' => 'task_cancelled',
            'taskId' => $taskId,
            'timestamp' => time(),
        ]);
    }

    protected function sendToConnection(ConnectionInterface $conn, array $data)
    {
        $conn->send(json_encode($data));
    }

    protected function broadcast(array $data)
    {
        $message = json_encode($data);
        foreach ($this->clients as $client) {
            $client->send($message);
        }
    }

    protected function sendError(ConnectionInterface $conn, string $error)
    {
        $this->sendToConnection($conn, [
            'type' => 'error',
            'error' => $error,
            'timestamp' => time(),
        ]);
    }

    protected function getRecentFiles(): array
    {
        // Get recently accessed files from current directory
        $files = [];
        $iterator = new \DirectoryIterator(getcwd());

        foreach ($iterator as $file) {
            if (! $file->isDot() && $file->isFile()) {
                $files[] = $file->getFilename();
                if (count($files) >= 10) {
                    break;
                }
            }
        }

        return $files;
    }

    protected function log(string $message)
    {
        if ($this->output) {
            $this->output->writeln("<info>[WebSocket]</info> {$message}");
        }
    }
}
