<?php

namespace App\Http;

use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\WebSocket\WsServer;

class HybridWebUIHandler implements HttpServerInterface
{
    protected WsServer $wsServer;

    public function __construct(MessageComponentInterface $wsHandler)
    {
        $this->wsServer = new WsServer($wsHandler);
    }

    public function onOpen(ConnectionInterface $conn, ?RequestInterface $request = null)
    {
        if (! $request) {
            $conn->close();

            return;
        }

        // Check if this is a WebSocket upgrade request
        $headers = $request->getHeaders();
        if (isset($headers['Upgrade']) &&
            in_array('websocket', array_map('strtolower', $headers['Upgrade']))) {
            // Delegate to WsServer which handles the handshake
            $this->wsServer->onOpen($conn, $request);

            return;
        }

        // This is a regular HTTP request
        $uri = $request->getUri()->getPath();

        // Serve the main WebUI HTML file
        if ($uri === '/' || $uri === '/webui.html') {
            $this->serveWebUI($conn);

            return;
        }

        // Handle other static files if needed
        if ($uri === '/favicon.ico') {
            $conn->send($this->createHttpResponse(404, 'Not Found'));
            $conn->close();

            return;
        }

        // Default 404
        $conn->send($this->createHttpResponse(404, 'Not Found', 'Page not found'));
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->wsServer->onMessage($from, $msg);
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->wsServer->onClose($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        error_log('WebUI Error: '.$e->getMessage());
        $this->wsServer->onError($conn, $e);
    }

    protected function serveWebUI(ConnectionInterface $conn): void
    {
        $htmlPath = base_path('public/webui.html');

        if (! file_exists($htmlPath)) {
            $conn->send($this->createHttpResponse(404, 'Not Found', 'WebUI file not found'));
            $conn->close();

            return;
        }

        $content = file_get_contents($htmlPath);
        $conn->send($this->createHttpResponse(200, 'OK', $content, 'text/html'));
        $conn->close();
    }

    protected function createHttpResponse(int $status, string $statusText, string $body = '', string $contentType = 'text/plain'): string
    {
        $contentLength = strlen($body);

        return "HTTP/1.1 {$status} {$statusText}\r\n".
               "Content-Type: {$contentType}; charset=UTF-8\r\n".
               "Content-Length: {$contentLength}\r\n".
               "Connection: close\r\n".
               "Access-Control-Allow-Origin: *\r\n".
               "\r\n".
               $body;
    }
}
