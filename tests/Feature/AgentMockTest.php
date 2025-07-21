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

// Test basic tool execution flow without actual API calls
it('executes tools with proper schema', function () {
    $agent = new Agent(
        tools: [
            new WriteFileTool(base_path('output/test')),
            new ReadFileTool(base_path('output/test')),
        ],
        goal: 'Test tool execution',
        maxIterations: 5
    );

    // Use reflection to check tool schemas
    $reflection = new ReflectionClass($agent);
    $schemaProperty = $reflection->getProperty('toolsSchema');
    $schemaProperty->setAccessible(true);
    $schemas = $schemaProperty->getValue($agent);

    // Check we have 2 tools
    expect($schemas)->toHaveCount(2);

    // Check write_file tool schema
    $writeFileSchema = null;
    foreach ($schemas as $schema) {
        if ($schema['name'] === 'write_file') {
            $writeFileSchema = $schema;
            break;
        }
    }

    expect($writeFileSchema)->not->toBeNull();
    expect($writeFileSchema['description'])->toBe('write a file from the local file system');
    expect($writeFileSchema['parameters']['properties'])->toHaveKey('filename');
    expect($writeFileSchema['parameters']['properties'])->toHaveKey('content');
    expect($writeFileSchema['parameters']['required'])->toBe(['filename', 'content']);
});

it('can execute write file tool directly', function () {
    $tool = new WriteFileTool(base_path('output/test'));

    $result = $tool->execute([
        'filename' => 'direct_test.txt',
        'content' => 'Direct execution test',
    ]);

    expect($result)->toContain('File written to');
    expect(file_exists(base_path('output/test/direct_test.txt')))->toBeTrue();
    expect(file_get_contents(base_path('output/test/direct_test.txt')))->toBe('Direct execution test');
});

it('can execute read file tool directly', function () {
    // Create a file first
    file_put_contents(base_path('output/test/read_test.txt'), 'Content to read');

    $tool = new ReadFileTool(base_path('output/test'));

    $result = $tool->execute([
        'filename' => 'read_test.txt',
    ]);

    expect($result)->toContain('File contents:');
    expect($result)->toContain('Content to read');
});

it('handles read file errors correctly', function () {
    $tool = new ReadFileTool(base_path('output/test'));

    $result = $tool->execute([
        'filename' => 'nonexistent.txt',
    ]);

    expect($result)->toContain('Error: File not found');
});

it('can execute run command tool', function () {
    $tool = new RunCommandTool;

    $result = $tool->execute([
        'command' => 'echo test output',
    ]);

    expect($result)->toContain('test output');
});

it('maintains intermediate steps tracking', function () {
    $agent = new Agent(
        tools: [new WriteFileTool(base_path('output/test'))],
        goal: 'Track steps',
        maxIterations: 5
    );

    // Use reflection to check intermediate steps
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('recordStep');
    $method->setAccessible(true);

    // Record some steps
    $method->invoke($agent, 'thought', 'Planning to write a file');
    $method->invoke($agent, 'action', ['action' => 'write_file', 'action_input' => ['filename' => 'test.txt', 'content' => 'test']]);
    $method->invoke($agent, 'observation', 'File written successfully');

    // Check intermediate steps
    $stepsProperty = $reflection->getProperty('intermediateSteps');
    $stepsProperty->setAccessible(true);
    $steps = $stepsProperty->getValue($agent);

    expect($steps)->toHaveCount(3);
    expect($steps[0]['type'])->toBe('thought');
    expect($steps[0]['content'])->toBe('Planning to write a file');
    expect($steps[1]['type'])->toBe('action');
    expect($steps[2]['type'])->toBe('observation');
});

it('trims intermediate steps when they exceed limit', function () {
    $agent = new Agent(
        tools: [],
        goal: 'Test step trimming',
        maxIterations: 10
    );

    // Use reflection to test trimming
    $reflection = new ReflectionClass($agent);
    $recordMethod = $reflection->getMethod('recordStep');
    $recordMethod->setAccessible(true);

    // Record more than 5 steps
    for ($i = 1; $i <= 8; $i++) {
        $recordMethod->invoke($agent, 'thought', "Thought number $i");
    }

    // Trigger trimming
    $trimMethod = $reflection->getMethod('trimIntermediateSteps');
    $trimMethod->setAccessible(true);
    $trimMethod->invoke($agent);

    // Check that only last 5 steps remain
    $stepsProperty = $reflection->getProperty('intermediateSteps');
    $stepsProperty->setAccessible(true);
    $steps = $stepsProperty->getValue($agent);

    expect($steps)->toHaveCount(5);
    expect($steps[0]['content'])->toBe('Thought number 4');
    expect($steps[4]['content'])->toBe('Thought number 8');
});

it('respects max iterations limit', function () {
    $agent = new Agent(
        tools: [],
        goal: 'Test iteration limit',
        maxIterations: 3
    );

    // Use reflection to check iteration tracking
    $reflection = new ReflectionClass($agent);
    $iterationProperty = $reflection->getProperty('currentIteration');
    $iterationProperty->setAccessible(true);

    // Initial iteration should be 0
    expect($iterationProperty->getValue($agent))->toBe(0);

    // Max iterations should be set
    $maxIterationProperty = $reflection->getProperty('maxIterations');
    $maxIterationProperty->setAccessible(true);
    expect($maxIterationProperty->getValue($agent))->toBe(3);
});

it('hooks work throughout agent execution', function () {
    $hooks = new Hooks;
    $events = [];

    $hooks->on('start', function ($task) use (&$events) {
        $events[] = ['type' => 'start', 'data' => $task];
    });

    $hooks->on('action', function ($action) use (&$events) {
        $events[] = ['type' => 'action', 'data' => $action];
    });

    $agent = new Agent(
        tools: [new WriteFileTool(base_path('output/test'))],
        goal: 'Test hooks',
        hooks: $hooks,
        maxIterations: 5
    );

    // Manually trigger start event (normally done in run())
    $hooks->trigger('start', 'Test task');

    // Manually trigger an action event
    $hooks->trigger('action', ['action' => 'test_action', 'action_input' => 'test']);

    expect($events)->toHaveCount(2);
    expect($events[0]['type'])->toBe('start');
    expect($events[0]['data'])->toBe('Test task');
    expect($events[1]['type'])->toBe('action');
    expect($events[1]['data']['action'])->toBe('test_action');
});
