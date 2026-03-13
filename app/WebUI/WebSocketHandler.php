<?php

namespace App\WebUI;

use App\WebUI\Messages\MessageHandler;
use App\WebUI\Session\WebUISessionManager;
use Exception;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;

class WebSocketHandler implements MessageComponentInterface
{
    protected SplObjectStorage $clients;

    protected WebUISessionManager $sessionManager;

    protected MessageHandler $messageHandler;

    protected Command $command;

    protected array $tools;

    public function __construct(array $tools, Command $command)
    {
        $this->clients = new SplObjectStorage;
        $this->sessionManager = new WebUISessionManager;
        $this->messageHandler = new MessageHandler($tools);
        $this->command = $command;
        $this->tools = $tools;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);

        // Create session for this connection
        $sessionId = $this->sessionManager->createSession($conn);

        $this->command->info("New WebUI connection ({$conn->resourceId}) - Session: {$sessionId}");

        // Send welcome message
        $this->sendToConnection($conn, [
            'type' => 'welcome',
            'sessionId' => $sessionId,
            'timestamp' => time(),
            'message' => 'Connected to Agent WebUI',
        ]);

        // Send initial status
        $this->sendToConnection($conn, [
            'type' => 'status',
            'status' => 'ready',
            'operation' => 'idle',
        ]);
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        try {
            $data = json_decode($msg, true);

            if (! $data) {
                throw new Exception('Invalid JSON message');
            }

            $sessionId = $this->sessionManager->getSessionId($from);
            if (! $sessionId) {
                throw new Exception('No session found for connection');
            }

            $this->command->line("<fg=cyan>WebUI</> [{$sessionId}]: {$data['type']}");

            // Handle the message
            $this->messageHandler->handleMessage($data, $sessionId, $from, $this);

        } catch (Exception $e) {
            $this->sendError($from, 'Message handling error: '.$e->getMessage());
            Log::error('WebSocket message error', [
                'error' => $e->getMessage(),
                'message' => $msg,
                'connection' => $from->resourceId,
            ]);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $sessionId = $this->sessionManager->getSessionId($conn);
        $this->clients->detach($conn);
        $this->sessionManager->destroySession($conn);

        $this->command->info("WebUI connection closed ({$conn->resourceId}) - Session: {$sessionId}");
    }

    public function onError(ConnectionInterface $conn, Exception $e): void
    {
        $sessionId = $this->sessionManager->getSessionId($conn);
        $this->command->error("WebUI error ({$conn->resourceId}): {$e->getMessage()}");

        $this->sendError($conn, 'Server error: '.$e->getMessage());

        Log::error('WebSocket connection error', [
            'error' => $e->getMessage(),
            'session' => $sessionId,
            'connection' => $conn->resourceId,
        ]);

        $conn->close();
    }

    /**
     * Send message to specific connection
     */
    public function sendToConnection(ConnectionInterface $conn, array $data): void
    {
        try {
            $conn->send(json_encode($data));
        } catch (Exception $e) {
            $this->command->error("Failed to send message: {$e->getMessage()}");
        }
    }

    /**
     * Broadcast message to all connected clients
     */
    public function broadcast(array $data): void
    {
        $message = json_encode($data);
        foreach ($this->clients as $client) {
            try {
                $client->send($message);
            } catch (Exception $e) {
                // Client may have disconnected
                $this->command->warn("Failed to broadcast to client: {$e->getMessage()}");
            }
        }
    }

    /**
     * Send error message to connection
     */
    public function sendError(ConnectionInterface $conn, string $error): void
    {
        $this->sendToConnection($conn, [
            'type' => 'error',
            'error' => $error,
            'timestamp' => time(),
        ]);
    }

    /**
     * Get command instance for logging
     */
    public function getCommand(): Command
    {
        return $this->command;
    }

    /**
     * Get session manager
     */
    public function getSessionManager(): WebUISessionManager
    {
        return $this->sessionManager;
    }
}
