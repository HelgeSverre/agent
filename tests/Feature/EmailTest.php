<?php

use App\Agent\Tool\ToolArgument;
use App\Tools\EmailToolkit\CreateDraftEmailTool;
use App\Tools\EmailToolkit\SearchEmailTool;
use Illuminate\Support\Carbon;

it('returns an array of ToolArgument objects from arguments method', function () {
    $testTool = new SearchEmailTool();

    $arguments = $testTool->arguments();

    expect($arguments)->toBeArray();

    foreach ($arguments as $argument) {
        expect($argument)->toBeInstanceOf(ToolArgument::class)
            ->and($argument->name)->toBeString()
            ->and($argument->type)->toBeString();
    }

    expect($arguments[0]->name)->toEqual('searchQuery')
        ->and($arguments[0]->type)->toEqual('string')
        ->and($arguments[0]->nullable)->toBeFalse()
        ->and($arguments[0]->description)->toEqual('');

    expect($arguments[1]->name)->toEqual('afterDate')
        ->and($arguments[1]->type)->toEqual('string')
        ->and($arguments[1]->nullable)->toBeFalse();

    expect($arguments[2]->name)->toEqual('fromDate')
        ->and($arguments[2]->type)->toEqual('string')
        ->and($arguments[2]->nullable)->toBeFalse()
        ->and($arguments[2]->description)->toBeNull();

});

it('returns a list of matching emails', function () {

    $testTool = new SearchEmailTool();

    $result = $testTool->run('ferdig signert', afterDate: Carbon::now()->subDays(90), fromDate: Carbon::now());

    dd($result);

});

it('can search for a word containing "ø"', function () {

    $testTool = new SearchEmailTool();

    $result = $testTool->run('finansiell helse', afterDate: Carbon::now()->subMonths(6), fromDate: Carbon::now());

    dd($result);

});

it('creates draft messages in draft folder', function () {

    $testTool = new CreateDraftEmailTool();

    $result = $testTool->run(
        subject: 'Test subject',
        body: 'Lorem nostrud id consectetur sit laboris occaecat quis occaecat cupidatat nulla velit enim fugiat dolor ipsum.',
        to: 'email@example.com'
    );

    dd($result);

});
