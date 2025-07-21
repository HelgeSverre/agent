<?php

use App\Agent\Hooks;

it('registers and triggers hooks', function () {
    $hooks = new Hooks;
    $called = false;
    $receivedData = null;

    $hooks->on('test_event', function ($data) use (&$called, &$receivedData) {
        $called = true;
        $receivedData = $data;
    });

    $hooks->trigger('test_event', 'test data');

    expect($called)->toBeTrue();
    expect($receivedData)->toBe('test data');
});

it('handles multiple listeners for the same event', function () {
    $hooks = new Hooks;
    $callCount = 0;
    $messages = [];

    $hooks->on('multi_event', function ($msg) use (&$callCount, &$messages) {
        $callCount++;
        $messages[] = "First: $msg";
    });

    $hooks->on('multi_event', function ($msg) use (&$callCount, &$messages) {
        $callCount++;
        $messages[] = "Second: $msg";
    });

    $hooks->trigger('multi_event', 'test');

    expect($callCount)->toBe(2);
    expect($messages)->toContain('First: test');
    expect($messages)->toContain('Second: test');
});

it('passes multiple arguments to hooks', function () {
    $hooks = new Hooks;
    $receivedArgs = [];

    $hooks->on('multi_arg', function (...$args) use (&$receivedArgs) {
        $receivedArgs = $args;
    });

    $hooks->trigger('multi_arg', 'arg1', 'arg2', ['arg3' => 'value']);

    expect($receivedArgs)->toHaveCount(3);
    expect($receivedArgs[0])->toBe('arg1');
    expect($receivedArgs[1])->toBe('arg2');
    expect($receivedArgs[2])->toBe(['arg3' => 'value']);
});

it('handles events with no listeners gracefully', function () {
    $hooks = new Hooks;

    // Should not throw exception
    expect(fn () => $hooks->trigger('nonexistent_event', 'data'))->not->toThrow(Exception::class);
});

it('supports all agent event types', function () {
    $hooks = new Hooks;
    $events = [];

    // Register listeners for all known event types
    $eventTypes = ['thought', 'prompt', 'action', 'observation', 'evaluation', 'final_answer', 'start', 'max_iteration'];

    foreach ($eventTypes as $event) {
        $hooks->on($event, function () use ($event, &$events) {
            $events[] = $event;
        });
    }

    // Trigger all events
    foreach ($eventTypes as $event) {
        $hooks->trigger($event, 'test');
    }

    // All events should have been recorded
    expect($events)->toHaveCount(count($eventTypes));
    foreach ($eventTypes as $event) {
        expect($events)->toContain($event);
    }
});

it('allows listeners to be closures with use statements', function () {
    $hooks = new Hooks;
    $externalVariable = 'external';
    $result = null;

    $hooks->on('closure_test', function ($data) use ($externalVariable, &$result) {
        $result = "$externalVariable: $data";
    });

    $hooks->trigger('closure_test', 'internal');

    expect($result)->toBe('external: internal');
});

it('executes listeners in the order they were registered', function () {
    $hooks = new Hooks;
    $order = [];

    $hooks->on('order_test', function () use (&$order) {
        $order[] = 1;
    });

    $hooks->on('order_test', function () use (&$order) {
        $order[] = 2;
    });

    $hooks->on('order_test', function () use (&$order) {
        $order[] = 3;
    });

    $hooks->trigger('order_test');

    expect($order)->toBe([1, 2, 3]);
});
