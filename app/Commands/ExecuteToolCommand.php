<?php

namespace App\Commands;

use App\Agent\Tool\Tool;
use App\Tools\BrowseWebsiteTool;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\SpeakTool;
use App\Tools\WriteFileTool;
use Exception;
use LaravelZero\Framework\Commands\Command;

class ExecuteToolCommand extends Command
{
    protected $signature = 'agent:execute-tool 
        {--tool= : Tool name to execute}
        {--args= : Base64 encoded JSON arguments}';

    protected $description = 'Execute a single tool in isolation';

    protected $hidden = true; // Hide from command list as it's internal

    /**
     * Map of tool names to tool classes
     */
    protected array $availableTools = [
        'read_file' => ReadFileTool::class,
        'write_file' => WriteFileTool::class,
        'search_web' => SearchWebTool::class,
        'browse_website' => BrowseWebsiteTool::class,
        'run_command' => RunCommandTool::class,
        'speak' => SpeakTool::class,
    ];

    public function handle(): int
    {
        $toolName = $this->option('tool');
        $encodedArgs = $this->option('args');

        if (! $toolName || ! $encodedArgs) {
            $this->outputError('Missing required options: --tool and --args');

            return 1;
        }

        try {
            // Decode arguments
            $args = json_decode(base64_decode($encodedArgs), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON arguments: '.json_last_error_msg());
            }

            // Load and execute tool
            $result = $this->executeTool($toolName, $args);

            // Output result as JSON
            $this->outputResult([
                'success' => true,
                'result' => $result,
                'tool' => $toolName,
            ]);

            return 0;

        } catch (Exception $e) {
            $this->outputError($e->getMessage());

            return 1;
        }
    }

    protected function executeTool(string $toolName, array $arguments): string
    {
        if (! isset($this->availableTools[$toolName])) {
            throw new Exception("Unknown tool: {$toolName}");
        }

        $toolClass = $this->availableTools[$toolName];

        // Special handling for WriteFileTool which needs base directory
        if ($toolClass === WriteFileTool::class) {
            $tool = new $toolClass(base_path('output'));
        } else {
            $tool = new $toolClass;
        }

        // Verify it's a valid tool
        if (! $tool instanceof Tool) {
            throw new Exception("Invalid tool class: {$toolClass}");
        }

        // Execute the tool
        return $tool->execute($arguments);
    }

    protected function outputResult(array $data): void
    {
        // Output to stdout as JSON for the parent process to read
        $this->line(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function outputError(string $message): void
    {
        // Output error as JSON
        $this->line(json_encode([
            'success' => false,
            'error' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
