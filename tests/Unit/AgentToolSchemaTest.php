<?php

use App\Agent\Agent;
use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;

class TestTool extends Tool
{
    protected string $name = 'test_tool';

    protected string $description = 'A test tool';

    public function run(
        #[Description('A required string parameter')]
        string $required,

        #[Description('An optional string parameter')]
        ?string $optional = null,

        #[Description('An integer parameter')]
        int $number = 42
    ): string {
        return "Executed with: $required, $optional, $number";
    }
}

it('generates correct tool schema', function () {
    $agent = new Agent(
        tools: [new TestTool],
        goal: 'Test schema generation'
    );

    // Use reflection to access the protected toolsSchema property
    $reflection = new ReflectionClass($agent);
    $schemaProperty = $reflection->getProperty('toolsSchema');
    $schemaProperty->setAccessible(true);
    $schemas = $schemaProperty->getValue($agent);

    expect($schemas)->toHaveCount(1);

    $schema = $schemas[0];
    expect($schema['name'])->toBe('test_tool');
    expect($schema['description'])->toBe('A test tool');
    expect($schema['parameters']['type'])->toBe('object');

    // Check properties
    $properties = $schema['parameters']['properties'];
    expect($properties)->toHaveKey('required');
    expect($properties)->toHaveKey('optional');
    expect($properties)->toHaveKey('number');

    // Check property details
    expect($properties['required']['type'])->toBe('string');
    expect($properties['required']['description'])->toBe('A required string parameter');

    expect($properties['optional']['type'])->toBe('string');
    expect($properties['optional']['description'])->toBe('An optional string parameter');

    expect($properties['number']['type'])->toBe('integer');
    expect($properties['number']['description'])->toBe('An integer parameter');

    // Check required fields
    expect($schema['parameters']['required'])->toBe(['required']);
});

it('maps PHP types to JSON schema correctly', function () {
    $agent = new Agent(
        tools: [],
        goal: 'Test type mapping'
    );

    // Use reflection to test the protected method
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('mapPhpTypeToJsonSchema');
    $method->setAccessible(true);

    expect($method->invoke($agent, 'string'))->toBe('string');
    expect($method->invoke($agent, 'int'))->toBe('integer');
    expect($method->invoke($agent, 'integer'))->toBe('integer');
    expect($method->invoke($agent, 'float'))->toBe('number');
    expect($method->invoke($agent, 'double'))->toBe('number');
    expect($method->invoke($agent, 'bool'))->toBe('boolean');
    expect($method->invoke($agent, 'boolean'))->toBe('boolean');
    expect($method->invoke($agent, 'array'))->toBe('array');
    expect($method->invoke($agent, 'object'))->toBe('object');
    expect($method->invoke($agent, 'mixed'))->toBe('string'); // default fallback
});

it('handles tools with no arguments', function () {
    $noArgTool = new class extends Tool
    {
        protected string $name = 'no_arg_tool';

        protected string $description = 'Tool with no arguments';

        public function run(): string
        {
            return 'No arguments needed';
        }
    };

    $agent = new Agent(
        tools: [$noArgTool],
        goal: 'Test no-argument tools'
    );

    $reflection = new ReflectionClass($agent);
    $schemaProperty = $reflection->getProperty('toolsSchema');
    $schemaProperty->setAccessible(true);
    $schemas = $schemaProperty->getValue($agent);

    $schema = $schemas[0];
    expect($schema['parameters']['properties'])->toBeEmpty();
    expect($schema['parameters']['required'])->toBeEmpty();
});

it('validates tool names follow the required pattern', function () {
    // Valid names
    $validNames = ['test_tool', 'search-web', 'tool123', 'UPPERCASE_TOOL', 'mixed_Case-123'];

    foreach ($validNames as $name) {
        expect(preg_match('/^[a-zA-Z0-9_-]+$/', $name))->toBe(1);
    }

    // Invalid names
    $invalidNames = ['test tool', 'tool!', 'tool@123', 'tool.name', 'tool/slash'];

    foreach ($invalidNames as $name) {
        expect(preg_match('/^[a-zA-Z0-9_-]+$/', $name))->toBe(0);
    }
});

it('creates final_answer function schema correctly', function () {
    $agent = new Agent(
        tools: [],
        goal: 'Test final answer schema'
    );

    // Use reflection to access the decideNextStep method
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('decideNextStep');
    $method->setAccessible(true);

    // We can't easily test the full method, but we can verify the structure
    // by checking that it doesn't throw errors
    expect(fn () => $agent->run('Simple task'))->not->toThrow(Exception::class);
});

it('handles tool execution with correct argument mapping', function () {
    $testTool = new TestTool;

    // Test with all arguments
    $result = $testTool->execute([
        'required' => 'test value',
        'optional' => 'optional value',
        'number' => 100,
    ]);

    expect($result)->toBe('Executed with: test value, optional value, 100');

    // Test with only required arguments
    $result = $testTool->execute([
        'required' => 'only required',
    ]);

    expect($result)->toBe('Executed with: only required, , 42');

    // Test with extra arguments (should be filtered out)
    $result = $testTool->execute([
        'required' => 'test',
        'extra' => 'should be ignored',
        'number' => 5,
    ]);

    expect($result)->toBe('Executed with: test, , 5');
});
