<?php

namespace App\Agent\Execution;

use Symfony\Component\Process\Process;

class ParallelExecutor
{
    /**
     * Maximum number of concurrent processes
     */
    protected int $maxProcesses;

    /**
     * Default timeout per tool in seconds
     */
    protected int $defaultTimeout;

    /**
     * Currently running processes
     */
    protected array $runningProcesses = [];

    /**
     * Queue of pending tool calls
     */
    protected array $queue = [];

    public function __construct(int $maxProcesses = 4, int $defaultTimeout = 30)
    {
        $this->maxProcesses = $maxProcesses;
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * Check if multiple tool calls can be parallelized
     */
    public function canParallelize(array $toolCalls): bool
    {
        // Need at least 2 tools to parallelize
        if (count($toolCalls) < 2) {
            return false;
        }

        // Check if tools have dependencies on each other
        // For now, we assume all tools are independent
        // In future, we could analyze if one tool's output is needed by another
        return $this->hasNoDependencies($toolCalls);
    }

    /**
     * Execute multiple tools in parallel
     */
    public function executeParallel(array $toolCalls): array
    {
        $results = [];
        $this->queue = $toolCalls;
        $this->runningProcesses = [];

        while (! empty($this->queue) || ! empty($this->runningProcesses)) {
            // Start new processes up to the limit
            $this->startProcesses();

            // Check for completed processes
            $this->checkProcesses($results);

            // Small sleep to prevent CPU spinning
            usleep(10000); // 10ms
        }

        return $results;
    }

    /**
     * Execute a single tool in an isolated process
     */
    public function executeSingle(array $toolCall): array
    {
        $process = $this->createProcess($toolCall);
        $process->run();

        return $this->processResult($toolCall['id'], $process);
    }

    /**
     * Start processes from queue up to max limit
     */
    protected function startProcesses(): void
    {
        while (count($this->runningProcesses) < $this->maxProcesses && ! empty($this->queue)) {
            $toolCall = array_shift($this->queue);

            $process = $this->createProcess($toolCall);
            $process->start();

            $this->runningProcesses[$toolCall['id']] = [
                'process' => $process,
                'toolCall' => $toolCall,
                'startTime' => microtime(true),
            ];
        }
    }

    /**
     * Check running processes for completion
     */
    protected function checkProcesses(array &$results): void
    {
        foreach ($this->runningProcesses as $id => $info) {
            $process = $info['process'];

            // Check if process is still running
            if ($process->isRunning()) {
                // Check for timeout
                $elapsed = microtime(true) - $info['startTime'];
                if ($elapsed > $this->defaultTimeout) {
                    $process->stop(5); // 5 second grace period
                    $results[$id] = [
                        'success' => false,
                        'error' => "Tool execution timed out after {$this->defaultTimeout} seconds",
                        'tool' => $info['toolCall']['tool'],
                    ];
                    unset($this->runningProcesses[$id]);
                }

                continue;
            }

            // Process completed
            $results[$id] = $this->processResult($id, $process);
            unset($this->runningProcesses[$id]);
        }
    }

    /**
     * Create a process for executing a tool
     */
    protected function createProcess(array $toolCall): Process
    {
        $args = base64_encode(json_encode($toolCall['arguments']));

        $command = [
            PHP_BINARY,
            base_path('agent'),
            'agent:execute-tool',
            '--tool='.$toolCall['tool'],
            '--args='.$args,
        ];

        $process = new Process($command);
        $process->setTimeout($this->defaultTimeout);

        return $process;
    }

    /**
     * Process the result from a completed process
     */
    protected function processResult(string $id, Process $process): array
    {
        $output = $process->getOutput();
        $exitCode = $process->getExitCode();

        // Try to decode JSON output
        $decoded = json_decode($output, true);

        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['success'])) {
            return $decoded;
        }

        // If not valid JSON or unexpected format, return error
        return [
            'success' => false,
            'error' => $exitCode !== 0
                ? "Process failed with exit code {$exitCode}: ".$process->getErrorOutput()
                : 'Invalid output format: '.substr($output, 0, 200),
            'tool' => $id,
        ];
    }

    /**
     * Check if tool calls have no dependencies
     * For now, this is a simple implementation
     */
    protected function hasNoDependencies(array $toolCalls): bool
    {
        // In the future, we could analyze if any tool needs output from another
        // For now, assume all tools are independent
        return true;
    }

    /**
     * Get maximum concurrent processes
     */
    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }

    /**
     * Set maximum concurrent processes
     */
    public function setMaxProcesses(int $max): void
    {
        $this->maxProcesses = max(1, $max);
    }
}
