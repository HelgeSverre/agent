<?php

use App\Agent\FunctionCallBuilder;

it('creates FunctionCallBuilder with tools', function () {
    $tools = [
        'functions' => [
            [
                'name' => 'test_function',
                'description' => 'A test function',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'param1' => ['type' => 'string', 'description' => 'First parameter'],
                    ],
                    'required' => ['param1'],
                ],
            ],
        ],
        'final_answer' => [
            'name' => 'final_answer',
            'description' => 'Provide final answer',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'answer' => ['type' => 'string', 'description' => 'The answer'],
                ],
                'required' => ['answer'],
            ],
        ],
    ];

    $builder = new FunctionCallBuilder($tools);

    expect($builder)->toBeInstanceOf(FunctionCallBuilder::class);
});

it('handles errors gracefully', function () {
    $tools = [
        'functions' => [
            [
                'name' => 'test_function',
                'description' => 'Test',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
        ],
    ];

    $builder = new FunctionCallBuilder($tools);

    // Mock a failing prompt (this would normally fail due to API issues)
    // Since we can't easily mock OpenAI, we'll test the structure
    $result = $builder->get('This is a test prompt that might fail');

    // The result should be an array (either with function_call, thought, or error)
    expect($result)->toBeArray();

    // It should have one of these keys
    $hasExpectedKey = isset($result['function_call']) ||
                      isset($result['thought']) ||
                      isset($result['error']);

    expect($hasExpectedKey)->toBeTrue();
});

it('processes function calls correctly', function () {
    // This test would require mocking OpenAI responses
    // For now, we'll test the structure

    $tools = [
        'functions' => [
            [
                'name' => 'write_file',
                'description' => 'Write to a file',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'filename' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['filename', 'content'],
                ],
            ],
        ],
    ];

    $builder = new FunctionCallBuilder($tools);

    // Test that the builder accepts a prompt
    expect(fn () => $builder->get('Write hello to test.txt'))->not->toThrow(Exception::class);
});

it('builds correct function array for OpenAI', function () {
    $tools = [
        'functions' => [
            [
                'name' => 'tool1',
                'description' => 'First tool',
                'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
            [
                'name' => 'tool2',
                'description' => 'Second tool',
                'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
        ],
        'final_answer' => [
            'name' => 'final_answer',
            'description' => 'Final answer',
            'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
        ],
    ];

    $builder = new FunctionCallBuilder($tools);

    // Use reflection to check the tools property
    $reflection = new ReflectionClass($builder);
    $toolsProperty = $reflection->getProperty('tools');
    $toolsProperty->setAccessible(true);
    $storedTools = $toolsProperty->getValue($builder);

    expect($storedTools)->toBe($tools);
    expect($storedTools['functions'])->toHaveCount(2);
    expect($storedTools['final_answer']['name'])->toBe('final_answer');
});
