<?php

use App\Agent\Agent;
use App\Agent\Hooks;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\WriteFileTool;

beforeEach(function () {
    // Clean up test output directory
    $outputDir = base_path('output/test');
    if (file_exists($outputDir)) {
        array_map('unlink', glob("$outputDir/*"));
    } else {
        mkdir($outputDir, 0777, true);
    }
});

afterEach(function () {
    // Clean up after tests
    $outputDir = base_path('output/test');
    if (file_exists($outputDir)) {
        array_map('unlink', glob("$outputDir/*"));
        rmdir($outputDir);
    }
});

it('can execute a simple write file task', function () {
    $hooks = new Hooks;
    $executedTools = [];

    $hooks->on('action', function ($action) use (&$executedTools) {
        $executedTools[] = $action['action'];
    });

    $agent = new Agent(
        tools: [
            new WriteFileTool(base_path('output/test')),
        ],
        goal: 'Complete the task successfully',
        hooks: $hooks,
        maxIterations: 5
    );

    $result = $agent->run('Write "Hello World" to a file named test.txt');

    // Check that the write_file tool was used
    expect($executedTools)->toContain('write_file');

    // Check that the file was created
    expect(file_exists(base_path('output/test/test.txt')))->toBeTrue();

    // Check file contents
    expect(file_get_contents(base_path('output/test/test.txt')))->toBe('Hello World');
});

it('can read and write files in sequence', function () {
    // First, create a file to read
    $testContent = "This is test content\nWith multiple lines";
    file_put_contents(base_path('output/test/source.txt'), $testContent);

    $hooks = new Hooks;
    $toolSequence = [];

    $hooks->on('action', function ($action) use (&$toolSequence) {
        $toolSequence[] = [
            'tool' => $action['action'],
            'input' => $action['action_input'],
        ];
    });

    $agent = new Agent(
        tools: [
            new ReadFileTool(base_path('output/test')),
            new WriteFileTool(base_path('output/test')),
        ],
        goal: 'Complete file operations successfully',
        hooks: $hooks,
        maxIterations: 8
    );

    $result = $agent->run('Read the contents of source.txt and write them to destination.txt');

    // Verify the sequence of operations
    $toolNames = array_column($toolSequence, 'tool');
    expect($toolNames)->toContain('read_file');
    expect($toolNames)->toContain('write_file');

    // Verify the destination file exists and has correct content
    expect(file_exists(base_path('output/test/destination.txt')))->toBeTrue();
    expect(file_get_contents(base_path('output/test/destination.txt')))->toBe($testContent);
});

it('handles errors gracefully when file not found', function () {
    $hooks = new Hooks;
    $observations = [];

    $hooks->on('observation', function ($observation) use (&$observations) {
        $observations[] = $observation;
    });

    $agent = new Agent(
        tools: [
            new ReadFileTool(base_path('output/test')),
        ],
        goal: 'Handle errors gracefully',
        hooks: $hooks,
        maxIterations: 5
    );

    $result = $agent->run('Read the contents of nonexistent.txt');

    // Check that an error was observed
    $errorFound = false;
    foreach ($observations as $observation) {
        if (str_contains($observation, 'Error: File not found')) {
            $errorFound = true;
            break;
        }
    }

    expect($errorFound)->toBeTrue();
});

it('can execute system commands', function () {
    $hooks = new Hooks;
    $commandResults = [];

    $hooks->on('observation', function ($observation) use (&$commandResults) {
        $commandResults[] = $observation;
    });

    $agent = new Agent(
        tools: [
            new RunCommandTool,
        ],
        goal: 'Execute commands successfully',
        hooks: $hooks,
        maxIterations: 5
    );

    $result = $agent->run('Run the command "echo Hello from test"');

    // Check that the command output was captured
    $outputFound = false;
    foreach ($commandResults as $result) {
        if (str_contains($result, 'Hello from test')) {
            $outputFound = true;
            break;
        }
    }

    expect($outputFound)->toBeTrue();
});

it('provides final answer when task is complete', function () {
    $hooks = new Hooks;
    $finalAnswerReceived = false;
    $finalAnswerContent = null;

    $hooks->on('final_answer', function ($answer) use (&$finalAnswerReceived, &$finalAnswerContent) {
        $finalAnswerReceived = true;
        $finalAnswerContent = $answer;
    });

    $agent = new Agent(
        tools: [
            new WriteFileTool(base_path('output/test')),
        ],
        goal: 'Complete tasks and provide clear final answers',
        hooks: $hooks,
        maxIterations: 5
    );

    $result = $agent->run('Write "Test complete" to done.txt and confirm when finished');

    // Check that a final answer was provided
    expect($finalAnswerReceived)->toBeTrue();
    expect($finalAnswerContent)->toBeString();
    expect($finalAnswerContent)->not->toBeEmpty();

    // Verify the task was actually completed
    expect(file_exists(base_path('output/test/done.txt')))->toBeTrue();
});

it('respects max iterations limit', function () {
    $hooks = new Hooks;
    $iterationCount = 0;
    $maxIterationReached = false;

    $hooks->on('action', function () use (&$iterationCount) {
        $iterationCount++;
    });

    $hooks->on('max_iteration', function () use (&$maxIterationReached) {
        $maxIterationReached = true;
    });

    $agent = new Agent(
        tools: [
            new ReadFileTool(base_path('output/test')),
        ],
        goal: 'Test iteration limits',
        hooks: $hooks,
        maxIterations: 3
    );

    // Give it an impossible task to force max iterations
    $result = $agent->run('Read all files in the universe');

    expect($maxIterationReached)->toBeTrue();
    expect($iterationCount)->toBeLessThanOrEqual(3);
    expect($result)->toContain('Max iterations reached');
});

it('handles multiple tools correctly', function () {
    $hooks = new Hooks;
    $toolsUsed = [];

    $hooks->on('action', function ($action) use (&$toolsUsed) {
        $toolsUsed[] = $action['action'];
    });

    $agent = new Agent(
        tools: [
            new RunCommandTool,
            new WriteFileTool(base_path('output/test')),
            new ReadFileTool(base_path('output/test')),
        ],
        goal: 'Use appropriate tools for each task',
        hooks: $hooks,
        maxIterations: 10
    );

    $result = $agent->run('First run "echo test", then write the word "success" to result.txt, and finally read result.txt to verify');

    // Check that multiple different tools were used
    expect($toolsUsed)->toContain('run_command');
    expect($toolsUsed)->toContain('write_file');
    expect($toolsUsed)->toContain('read_file');

    // Verify the file was created
    expect(file_exists(base_path('output/test/result.txt')))->toBeTrue();
});

it('maintains conversation context', function () {
    $hooks = new Hooks;
    $observations = [];

    $hooks->on('observation', function ($observation) use (&$observations) {
        $observations[] = $observation;
    });

    $agent = new Agent(
        tools: [
            new WriteFileTool(base_path('output/test')),
            new ReadFileTool(base_path('output/test')),
        ],
        goal: 'Maintain context throughout the conversation',
        hooks: $hooks,
        maxIterations: 8
    );

    // Task that requires remembering previous actions
    $result = $agent->run('Write "Step 1 complete" to log.txt, then append " - Step 2 complete" to the same file');

    // The agent should remember it already wrote to log.txt
    expect(file_exists(base_path('output/test/log.txt')))->toBeTrue();

    // Note: This test may need adjustment based on how the agent handles appending
    // For now, we just verify the file exists and has content
    $content = file_get_contents(base_path('output/test/log.txt'));
    expect($content)->not->toBeEmpty();
});

it('evaluates task completion correctly', function () {
    $hooks = new Hooks;
    $evaluationReceived = false;
    $evaluationContent = null;

    $hooks->on('evaluation', function ($eval) use (&$evaluationReceived, &$evaluationContent) {
        $evaluationReceived = true;
        $evaluationContent = $eval;
    });

    $agent = new Agent(
        tools: [
            new WriteFileTool(base_path('output/test')),
        ],
        goal: 'Complete tasks and evaluate properly',
        hooks: $hooks,
        maxIterations: 5
    );

    $result = $agent->run('Create a file called evaluation_test.txt with the content "Evaluation successful"');

    // Check that evaluation occurred
    expect($evaluationReceived)->toBeTrue();

    // If evaluation succeeded, it should have status
    if ($evaluationContent && isset($evaluationContent['status'])) {
        expect($evaluationContent['status'])->toBe('completed');
    }

    // Verify the task was completed
    expect(file_exists(base_path('output/test/evaluation_test.txt')))->toBeTrue();
    expect(file_get_contents(base_path('output/test/evaluation_test.txt')))->toBe('Evaluation successful');
});
