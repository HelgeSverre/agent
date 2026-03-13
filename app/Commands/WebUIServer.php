<?php

namespace App\Commands;

use App\Http\HybridWebUIHandler;
use App\Tools\BrowseWebsiteTool;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\SpeakTool;
use App\Tools\WriteFileTool;
use App\WebUI\WebSocketHandler;
use LaravelZero\Framework\Commands\Command;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;

class WebUIServer extends Command
{
    protected $signature = 'web {--port=8080 : WebSocket server port} 
        {--host=127.0.0.1 : Host to bind to}
        {--open : Open browser automatically}';

    protected $description = 'Start the web UI server for agent interaction';

    public function handle(): void
    {
        $port = (int) $this->option('port');
        $host = (string) $this->option('host');

        $wsHandler = new WebSocketHandler($this->createTools(), $this);
        $hybridHandler = new HybridWebUIHandler($wsHandler);
        $server = IoServer::factory(
            new HttpServer($hybridHandler),
            $port,
            $host
        );

        $this->newLine();
        $this->line("  <fg=green>Web UI</>    <fg=cyan>http://{$host}:{$port}</>");
        $this->line("  <fg=green>WebSocket</>  <fg=cyan>ws://{$host}:{$port}</>");
        $this->newLine();
        $this->line('  Press <fg=yellow>Ctrl+C</> to stop');
        $this->newLine();

        if ($this->option('open')) {
            $this->openBrowser("http://{$host}:{$port}");
        }

        $server->run();
    }

    protected function createTools(): array
    {
        return [
            new ReadFileTool,
            new WriteFileTool(base_path('output')),
            new SearchWebTool,
            new BrowseWebsiteTool,
            new RunCommandTool,
            new SpeakTool,
        ];
    }

    protected function openBrowser(string $url): void
    {
        $os = strtolower(PHP_OS);

        if (str_contains($os, 'darwin')) {
            exec("open {$url}");
        } elseif (str_contains($os, 'win')) {
            exec("start {$url}");
        } elseif (str_contains($os, 'linux')) {
            exec("xdg-open {$url}");
        }
    }
}
