<?php

namespace App\Tools;

use App\Agent\Tool\Attributes\AsTool;
use App\Agent\Tool\Attributes\Description;
use App\Agent\Tool\Tool;
use Symfony\Component\Process\Process;

#[AsTool(
    name: 'run_command',
    description: 'Run a command on the terminal and get the output, useful for running command line tools or listing files etc'
)]
class RunCommandTool extends Tool
{
    public function run(
        #[Description('The command to run')]
        string $command,
    ): string {
        // Validate command is not empty
        if (empty(trim($command))) {
            return "Error: Command cannot be empty. Please provide a valid command to execute.";
        }
        
        // Parse command properly - handle quoted arguments
        $commandParts = str_getcsv($command, ' ');
        
        // Check if command exists
        $executable = $commandParts[0] ?? '';
        if (!$this->isCommandSafe($executable)) {
            return "Error: Command '{$executable}' is not allowed for security reasons.";
        }
        
        try {
            $process = new Process($commandParts);
            $process->setTimeout(30); // 30 second timeout
            $process->run();
            
            if (!$process->isSuccessful()) {
                return "Error executing command: " . $process->getErrorOutput();
            }

            return $process->getOutput() ?: "Command executed successfully (no output)";
        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }
    
    /**
     * Check if a command is safe to execute
     */
    private function isCommandSafe(string $command): bool
    {
        // Whitelist of safe commands
        $safeCommands = [
            'ls', 'pwd', 'echo', 'cat', 'grep', 'find', 'wc', 'head', 'tail',
            'sort', 'uniq', 'cut', 'sed', 'awk', 'date', 'whoami', 'hostname',
            'php', 'composer', 'git', 'npm', 'node', 'python', 'pip'
        ];
        
        return in_array($command, $safeCommands);
    }
}
