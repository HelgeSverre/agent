<?php

use App\Agent\Tool\Description;
use App\Agent\Tool\Tool;
use App\Agent\Tool\ToolArgument;

$closure = fn (
    #[Description('The location to get the weather from')]
    string $location,
    #[Description('The unit to return the temperature in')]
    string $unit,
    #[Description('The conversion factor to use')]
    float $conversionFactor = 1.0,
    int $someInteger = 66,
    #[Description('Should we include participation information with the weather report?')]
    bool $includeParticipation = false,

) => "$location $unit";

it('returns an array of ToolArgument objects from arguments method', function () use ($closure) {
    $tool = Tool::fromClosure(
        name: 'tool_name',
        description: 'tool_description',
        closure: $closure
    );

    $arguments = $tool->arguments();

    expect($tool->name())->toEqual('tool_name')
        ->and($tool->description())->toEqual('tool_description')
        ->and($arguments)->toBeArray();

    foreach ($arguments as $argument) {
        expect($argument)->toBeInstanceOf(ToolArgument::class)
            ->and($argument->name)->toBeString()
            ->and($argument->type)->toBeString();
    }

    expect($arguments[0]->name)->toEqual('location')
        ->and($arguments[0]->type)->toEqual('string')
        ->and($arguments[0]->nullable)->toBeFalse()
        ->and($arguments[0]->description)->toEqual('The location to get the weather from')
        ->and($arguments[1]->name)->toEqual('unit')
        ->and($arguments[1]->type)->toEqual('string')
        ->and($arguments[1]->nullable)->toBeFalse()
        ->and($arguments[1]->description)->toEqual('The unit to return the temperature in')
        ->and($arguments[2]->name)->toEqual('conversionFactor')
        ->and($arguments[2]->type)->toEqual('float')
        ->and($arguments[2]->nullable)->toBeFalse()
        ->and($arguments[2]->description)->toEqual('The conversion factor to use')
        ->and($arguments[3]->name)->toEqual('someInteger')
        ->and($arguments[3]->type)->toEqual('int')
        ->and($arguments[3]->nullable)->toBeFalse()
        ->and($arguments[3]->description)->toBeNull()
        ->and($arguments[4]->name)->toEqual('includeParticipation')
        ->and($arguments[4]->type)->toEqual('bool')
        ->and($arguments[4]->nullable)->toBeFalse()
        ->and($arguments[4]->description)->toEqual('Should we include participation information with the weather report?');

});
