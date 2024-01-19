<?php

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use App\Agent\Tool\ToolArgument;
use Illuminate\Support\Carbon;

// Mock subclass of Tool for testing
class TestTool extends Tool
{
    public function name(): string
    {
        return 'Test Tool';
    }

    public function description(): string
    {
        return 'This is a test tool.';
    }

    public function run(
        string $arg1,
        #[Description('arg2 has a description')]
        int $arg2,
        ?string $arg3 = null,
    ) {
        return "Running with $arg1 and $arg2";
    }
}

// Mock subclass of Tool for testing
class TestToolProperties extends Tool
{
    protected string $name = 'Test Tool';

    protected string $description = 'This is a test tool.';

    public function run(
        string $arg1,
        #[Description('arg2 has a description')]
        int $arg2,
        ?string $arg3 = null,
    ) {
        return "Running with $arg1 and $arg2";
    }
}

it('returns an array of ToolArgument objects from arguments method', function () {
    $testTool = new TestTool();

    $arguments = $testTool->arguments();

    expect($arguments)->toBeArray();

    foreach ($arguments as $argument) {
        expect($argument)->toBeInstanceOf(ToolArgument::class)
            ->and($argument->name)->toBeString()
            ->and($argument->type)->toBeString();
    }

    expect($arguments[0]->name)->toEqual('arg1')
        ->and($arguments[0]->type)->toEqual('string')
        ->and($arguments[0]->nullable)->toBeFalse()
        ->and($arguments[0]->description)->toEqual('');

    expect($arguments[1]->name)->toEqual('arg2')
        ->and($arguments[1]->type)->toEqual('int')
        ->and($arguments[1]->nullable)->toBeFalse()
        ->and($arguments[1]->description)->toEqual('arg2 has a description');

    expect($arguments[2]->name)->toEqual('arg3')
        ->and($arguments[2]->type)->toEqual('string')
        ->and($arguments[2]->nullable)->toBeTrue()
        ->and($arguments[2]->description)->toBeNull();

});

it('invokes the run method when called', function () {
    $testTool = new TestTool();

    $result = $testTool->run('arg1', 123);

    expect($result)->toEqual('Running with arg1 and 123');
});

it('converts string input to Date objects', function () {
    $tool = new class extends Tool
    {
        protected string $name = 'Test';

        protected string $description = 'Test';

        public function run(
            \Carbon\Carbon $carbon,
            \Carbon\CarbonImmutable $carbonImmutable,
            DateTime $dateTime,
            DateTimeImmutable $dateTimeImmutable,
            ?Carbon $nullable = null
        ) {

            expect($carbon)->toEqual(Carbon::parse('2020-01-01'));
            expect($carbonImmutable)->toEqual(Carbon::parse('2020-01-02'));
            expect($dateTime)->toEqual(Carbon::parse('2020-01-03'));
            expect($dateTimeImmutable)->toEqual(Carbon::parse('2020-01-04'));
            expect($nullable)->toBeNull();

            return 'noop';
        }
    };

    $result = $tool->execute([
        'carbon' => '2020-01-01',
        'carbonImmutable' => '2020-01-02',
        'dateTime' => '2020-01-03',
        'dateTimeImmutable' => '2020-01-04',
    ]);

});

it('it trims extra inputs not defined in the tool', function () {
    // The code will error if this test fails.
    $tool = new class extends Tool
    {
        protected string $name = 'Test';

        protected string $description = 'Test';

        public function run(
            string $arg1,
        ) {
            return 'noop';
        }
    };

    $result = $tool->execute([
        'arg1' => 'test',
        'arg2' => 'test', // should be ignored
    ]);

    expect($result)->toEqual('noop');

});

it('defers to properties if name and description are defined', function () {
    $testTool = new TestToolProperties();

    expect($testTool->name())->toEqual('Test Tool')
        ->and($testTool->description())->toEqual('This is a test tool.');
});

it('throws an exception if run method is not implemented', function () {
    $tool = new class extends Tool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test Description';
        }
    };

    $tool->execute('some arg');
})->throws(Exception::class, 'Tool must implement a run method');
