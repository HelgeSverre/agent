<?php

namespace App\WebUI;

use App\WebUI\Messages\MessageHandler;
use App\WebUI\Session\WebUISessionManager;
use Exception;
use LaravelZero\Framework\Commands\Command;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

class ReactWebSocketServer
{
    protected WebUISessionManager $sessionManager;

    protected MessageHandler $messageHandler;

    protected Command $command;

    protected array $tools;

    protected array $connections = [];

    public function __construct(array $tools, Command $command)
    {
        $this->sessionManager = new WebUISessionManager;
        $this->messageHandler = new MessageHandler($tools);
        $this->command = $command;
        $this->tools = $tools;
    }

    public function listen(SocketServer $socket): void
    {
        $socket->on('connection', function (ConnectionInterface $connection) {
            $this->handleConnection($connection);
        });
    }

    protected function handleConnection(ConnectionInterface $connection): void
    {
        $this->command->info("New connection from {$connection->getRemoteAddress()}");

        $connectionId = spl_object_id($connection);
        $this->connections[$connectionId] = $connection;

        // Handle WebSocket handshake
        $connection->on('data', function ($data) use ($connection, $connectionId) {
            try {
                // Simple WebSocket handshake detection
                if (str_contains($data, 'Upgrade: websocket')) {
                    $this->performWebSocketHandshake($connection, $data);

                    return;
                }

                // Handle WebSocket frame
                $this->handleWebSocketFrame($connection, $data, $connectionId);

            } catch (Exception $e) {
                $this->command->error("Connection error: {$e->getMessage()}");
                $connection->close();
            }
        });

        $connection->on('close', function () use ($connectionId) {
            $this->handleConnectionClose($connectionId);
        });

        $connection->on('error', function (Exception $e) use ($connectionId) {
            $this->command->error("Connection error: {$e->getMessage()}");
            $this->handleConnectionClose($connectionId);
        });
    }

    protected function performWebSocketHandshake(ConnectionInterface $connection, string $data): void
    {
        // Extract WebSocket key from request
        if (! preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $data, $matches)) {
            $connection->close();

            return;
        }

        $key = trim($matches[1]);
        $acceptKey = base64_encode(pack('H*', sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        // Send WebSocket handshake response
        $response = "HTTP/1.1 101 Switching Protocols\r\n".
                   "Upgrade: websocket\r\n".
                   "Connection: Upgrade\r\n".
                   "Sec-WebSocket-Accept: {$acceptKey}\r\n".
                   "\r\n";

        $connection->write($response);

        // Create session and send welcome message
        $sessionId = $this->sessionManager->createSession($connection);

        $this->command->info("WebSocket handshake completed - Session: {$sessionId}");

        $this->sendWebSocketMessage($connection, [
            'type' => 'welcome',
            'sessionId' => $sessionId,
            'timestamp' => time(),
            'message' => 'Connected to Agent WebUI',
        ]);

        $this->sendWebSocketMessage($connection, [
            'type' => 'status',
            'status' => 'ready',
            'operation' => 'idle',
        ]);
    }

    protected function handleWebSocketFrame(ConnectionInterface $connection, string $data, int $connectionId): void
    {
        // Simple WebSocket frame parsing (for text frames)
        if (strlen($data) < 2) {
            return;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        // Check if this is a text frame (opcode 1) and final frame
        $opcode = $firstByte & 0xF;
        $masked = ($secondByte & 0x80) === 0x80;

        if ($opcode !== 1) {
            return;
        } // Only handle text frames

        $payloadLength = $secondByte & 0x7F;
        $maskStart = 2;

        if ($payloadLength === 126) {
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
            $maskStart = 4;
        } elseif ($payloadLength === 127) {
            // 64-bit length (not implemented for simplicity)
            return;
        }

        if ($masked) {
            $mask = substr($data, $maskStart, 4);
            $payload = substr($data, $maskStart + 4, $payloadLength);

            // Unmask payload
            for ($i = 0; $i < $payloadLength; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        } else {
            $payload = substr($data, $maskStart, $payloadLength);
        }

        // Handle the message
        try {
            $messageData = json_decode($payload, true);
            if (! $messageData) {
                throw new Exception('Invalid JSON message');
            }

            $sessionId = $this->sessionManager->getSessionId($connection);
            if (! $sessionId) {
                throw new Exception('No session found for connection');
            }

            $this->command->line("<fg=cyan>WebUI</> [{$sessionId}]: {$messageData['type']}");

            // Handle the message through MessageHandler
            $this->messageHandler->handleMessage($messageData, $sessionId, $connection, $this);

        } catch (Exception $e) {
            $this->sendError($connection, 'Message handling error: '.$e->getMessage());
        }
    }

    protected function handleConnectionClose(int $connectionId): void
    {
        if (isset($this->connections[$connectionId])) {
            $connection = $this->connections[$connectionId];
            $sessionId = $this->sessionManager->getSessionId($connection);
            $this->sessionManager->destroySession($connection);
            unset($this->connections[$connectionId]);

            $this->command->info("Connection closed - Session: {$sessionId}");
        }
    }

    public function sendWebSocketMessage(ConnectionInterface $connection, array $data): void
    {
        try {
            $payload = json_encode($data);
            $frame = $this->createWebSocketFrame($payload);
            $connection->write($frame);
        } catch (Exception $e) {
            $this->command->error("Failed to send message: {$e->getMessage()}");
        }
    }

    protected function createWebSocketFrame(string $payload): string
    {
        $payloadLength = strlen($payload);

        // Create frame header
        $frame = pack('C', 0x81); // FIN = 1, opcode = 1 (text)

        if ($payloadLength < 126) {
            $frame .= pack('C', $payloadLength);
        } elseif ($payloadLength < 65536) {
            $frame .= pack('C', 126);
            $frame .= pack('n', $payloadLength);
        } else {
            $frame .= pack('C', 127);
            $frame .= pack('NN', 0, $payloadLength);
        }

        $frame .= $payload;

        return $frame;
    }

    public function sendError(ConnectionInterface $connection, string $error): void
    {
        $this->sendWebSocketMessage($connection, [
            'type' => 'error',
            'error' => $error,
            'timestamp' => time(),
        ]);
    }

    public function getCommand(): Command
    {
        return $this->command;
    }

    public function getSessionManager(): WebUISessionManager
    {
        return $this->sessionManager;
    }

    // Adapter methods for MessageHandler compatibility
    public function sendToConnection(ConnectionInterface $connection, array $data): void
    {
        $this->sendWebSocketMessage($connection, $data);
    }

    public function broadcast(array $data): void
    {
        foreach ($this->connections as $connection) {
            $this->sendWebSocketMessage($connection, $data);
        }
    }
}
