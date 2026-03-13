<?php

namespace Tests\Unit\WebSocket;

use App\Agent\Hooks;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;
use App\WebSocket\AgentWebSocketHandler;
use Exception;
use Mockery;
use Ratchet\ConnectionInterface;
use ReflectionClass;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

class AgentWebSocketHandlerTest extends TestCase
{
    private AgentWebSocketHandler $handler;

    private array $mockTools;

    private OutputInterface $mockOutput;

    private ConnectionInterface $mockConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTools = [
            new ReadFileTool,
            new WriteFileTool('/tmp/test'),
        ];

        $this->mockOutput = Mockery::mock(OutputInterface::class);
        $this->mockConnection = Mockery::mock(ConnectionInterface::class);

        $this->handler = new AgentWebSocketHandler($this->mockTools, $this->mockOutput);

        // Set up connection ID for testing
        $this->mockConnection->resourceId = 'test-connection-123';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_constructor_initializes_properties()
    {
        $reflection = new ReflectionClass($this->handler);

        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clients = $clientsProperty->getValue($this->handler);

        $toolsProperty = $reflection->getProperty('tools');
        $toolsProperty->setAccessible(true);
        $tools = $toolsProperty->getValue($this->handler);

        $outputProperty = $reflection->getProperty('output');
        $outputProperty->setAccessible(true);
        $output = $outputProperty->getValue($this->handler);

        $this->assertInstanceOf(\SplObjectStorage::class, $clients);
        $this->assertEquals($this->mockTools, $tools);
        $this->assertEquals($this->mockOutput, $output);
    }

    public function test_on_open_attaches_client_and_sends_welcome_messages()
    {
        $this->mockOutput->shouldReceive('writeln')
            ->with(Mockery::pattern('/New connection: test-connection-123/'))
            ->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'connection' &&
                       $decoded['status'] === 'connected' &&
                       $decoded['connectionId'] === 'test-connection-123';
            }))
            ->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'context' &&
                       isset($decoded['data']['directory']) &&
                       isset($decoded['data']['files']) &&
                       isset($decoded['data']['capabilities']);
            }))
            ->once();

        $this->handler->onOpen($this->mockConnection);

        // Verify client was attached
        $reflection = new ReflectionClass($this->handler);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clients = $clientsProperty->getValue($this->handler);

        $this->assertTrue($clients->contains($this->mockConnection));
    }

    public function test_on_message_handles_valid_command()
    {
        $command = 'test command';
        $message = json_encode([
            'type' => 'command',
            'command' => $command,
        ]);

        $this->mockOutput->shouldReceive('writeln')->atLeast()->once();

        // Mock the connection to expect task_started message
        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'task_started' &&
                       $decoded['command'] === 'test command' &&
                       isset($decoded['taskId']);
            }))
            ->once();

        // Mock for task completion/failure
        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return in_array($decoded['type'], ['task_completed', 'task_failed']);
            }))
            ->once();

        $this->handler->onMessage($this->mockConnection, $message);
    }

    public function test_on_message_handles_status_request()
    {
        $message = json_encode(['type' => 'status']);

        $this->mockOutput->shouldReceive('writeln')->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'status' &&
                       isset($decoded['activeAgents']) &&
                       isset($decoded['connectedClients']) &&
                       isset($decoded['timestamp']);
            }))
            ->once();

        $this->handler->onMessage($this->mockConnection, $message);
    }

    public function test_on_message_handles_cancel_request()
    {
        $taskId = 'test-task-123';
        $message = json_encode([
            'type' => 'cancel',
            'taskId' => $taskId,
        ]);

        $this->mockOutput->shouldReceive('writeln')->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) use ($taskId) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'task_cancelled' &&
                       $decoded['taskId'] === $taskId;
            }))
            ->once();

        $this->handler->onMessage($this->mockConnection, $message);
    }

    public function test_on_message_handles_invalid_json()
    {
        $invalidMessage = 'invalid json {';

        $this->mockOutput->shouldReceive('writeln')->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'error' &&
                       isset($decoded['error']);
            }))
            ->once();

        $this->handler->onMessage($this->mockConnection, $invalidMessage);
    }

    public function test_on_message_handles_missing_type()
    {
        $message = json_encode(['command' => 'test']);

        $this->mockOutput->shouldReceive('writeln')->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'error' &&
                       $decoded['error'] === 'Invalid message format';
            }))
            ->once();

        $this->handler->onMessage($this->mockConnection, $message);
    }

    public function test_on_message_handles_unknown_type()
    {
        $message = json_encode(['type' => 'unknown']);

        $this->mockOutput->shouldReceive('writeln')->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'error' &&
                       strpos($decoded['error'], 'Unknown message type: unknown') !== false;
            }))
            ->once();

        $this->handler->onMessage($this->mockConnection, $message);
    }

    public function test_on_close_detaches_client_and_cleans_up()
    {
        // First attach the client
        $this->handler->onOpen($this->mockConnection);

        $this->mockOutput->shouldReceive('writeln')->atLeast()->once();
        $this->mockConnection->shouldReceive('send')->atLeast()->once();

        // Mock the close action
        $this->mockOutput->shouldReceive('writeln')
            ->with(Mockery::pattern('/Connection closed: test-connection-123/'))
            ->once();

        $this->handler->onClose($this->mockConnection);

        // Verify client was detached
        $reflection = new ReflectionClass($this->handler);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clients = $clientsProperty->getValue($this->handler);

        $this->assertFalse($clients->contains($this->mockConnection));
    }

    public function test_on_error_logs_and_closes_connection()
    {
        $exception = new Exception('Test error');

        $this->mockOutput->shouldReceive('writeln')
            ->with(Mockery::pattern('/Error on connection test-connection-123: Test error/'))
            ->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'error' &&
                       strpos($decoded['error'], 'Connection error: Test error') !== false;
            }))
            ->once();

        $this->mockConnection->shouldReceive('close')->once();

        $this->handler->onError($this->mockConnection, $exception);
    }

    public function test_handle_command_with_empty_command()
    {
        $this->mockOutput->shouldReceive('writeln')->once();

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'error' &&
                       $decoded['error'] === 'Empty command';
            }))
            ->once();

        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('handleCommand');
        $method->setAccessible(true);

        $method->invoke($this->handler, $this->mockConnection, '');
    }

    public function test_create_hooks_for_connection_creates_all_hooks()
    {
        $taskId = 'test-task-123';

        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('createHooksForConnection');
        $method->setAccessible(true);

        $hooks = $method->invoke($this->handler, $this->mockConnection, $taskId);

        $this->assertInstanceOf(Hooks::class, $hooks);

        // Test that hooks are properly set up by triggering them
        $hookReflection = new ReflectionClass($hooks);
        $listenersProperty = $hookReflection->getProperty('listeners');
        $listenersProperty->setAccessible(true);
        $listeners = $listenersProperty->getValue($hooks);

        // Verify all expected hooks are registered
        $expectedHooks = ['start', 'iteration', 'action', 'thought', 'observation', 'tool_execution', 'final_answer'];

        foreach ($expectedHooks as $hookName) {
            $this->assertArrayHasKey($hookName, $listeners);
            $this->assertNotEmpty($listeners[$hookName]);
        }
    }

    public function test_hooks_send_correct_activity_messages()
    {
        $taskId = 'test-task-123';

        // Set up connection to expect activity messages
        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) use ($taskId) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'activity' &&
                       $decoded['taskId'] === $taskId &&
                       in_array($decoded['activity'], ['start', 'iteration', 'action', 'thought', 'observation', 'tool_execution', 'final_answer']);
            }))
            ->times(7); // One for each hook type

        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('createHooksForConnection');
        $method->setAccessible(true);

        $hooks = $method->invoke($this->handler, $this->mockConnection, $taskId);

        // Trigger each hook
        $hooks->trigger('start', 'test task');
        $hooks->trigger('iteration', 1);
        $hooks->trigger('action', ['action' => 'test']);
        $hooks->trigger('thought', 'test thought');
        $hooks->trigger('observation', 'test observation');
        $hooks->trigger('tool_execution', 'test_tool', ['input' => 'test']);
        $hooks->trigger('final_answer', 'test answer');
    }

    public function test_send_status_includes_correct_information()
    {
        // Add some mock active agents
        $reflection = new ReflectionClass($this->handler);
        $activeAgentsProperty = $reflection->getProperty('activeAgents');
        $activeAgentsProperty->setAccessible(true);
        $activeAgentsProperty->setValue($this->handler, ['agent1' => 'mock', 'agent2' => 'mock']);

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'status' &&
                       $decoded['activeAgents'] === 2 &&
                       is_int($decoded['connectedClients']) &&
                       is_int($decoded['timestamp']);
            }))
            ->once();

        $method = $reflection->getMethod('sendStatus');
        $method->setAccessible(true);
        $method->invoke($this->handler, $this->mockConnection);
    }

    public function test_get_recent_files_returns_file_list()
    {
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('getRecentFiles');
        $method->setAccessible(true);

        $files = $method->invoke($this->handler);

        $this->assertIsArray($files);
        $this->assertLessThanOrEqual(10, count($files));
    }

    public function test_send_to_connection_sends_json()
    {
        $data = ['type' => 'test', 'message' => 'hello'];

        $this->mockConnection->shouldReceive('send')
            ->with(json_encode($data))
            ->once();

        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('sendToConnection');
        $method->setAccessible(true);

        $method->invoke($this->handler, $this->mockConnection, $data);
    }

    public function test_broadcast_sends_to_all_clients()
    {
        // Add multiple mock connections
        $mockConn1 = Mockery::mock(ConnectionInterface::class);
        $mockConn2 = Mockery::mock(ConnectionInterface::class);

        $mockConn1->resourceId = 'conn1';
        $mockConn2->resourceId = 'conn2';

        // Simulate adding connections
        $reflection = new ReflectionClass($this->handler);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clients = $clientsProperty->getValue($this->handler);

        $clients->attach($mockConn1);
        $clients->attach($mockConn2);

        $data = ['type' => 'broadcast', 'message' => 'hello all'];
        $expectedJson = json_encode($data);

        $mockConn1->shouldReceive('send')->with($expectedJson)->once();
        $mockConn2->shouldReceive('send')->with($expectedJson)->once();

        $method = $reflection->getMethod('broadcast');
        $method->setAccessible(true);

        $method->invoke($this->handler, $data);
    }

    public function test_send_error_sends_error_message()
    {
        $errorMessage = 'Test error message';

        $this->mockConnection->shouldReceive('send')
            ->with(Mockery::on(function ($data) use ($errorMessage) {
                $decoded = json_decode($data, true);

                return $decoded['type'] === 'error' &&
                       $decoded['error'] === $errorMessage &&
                       isset($decoded['timestamp']);
            }))
            ->once();

        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('sendError');
        $method->setAccessible(true);

        $method->invoke($this->handler, $this->mockConnection, $errorMessage);
    }

    public function test_log_outputs_to_console_when_output_available()
    {
        $message = 'Test log message';

        $this->mockOutput->shouldReceive('writeln')
            ->with("<info>[WebSocket]</info> {$message}")
            ->once();

        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('log');
        $method->setAccessible(true);

        $method->invoke($this->handler, $message);
    }

    public function test_log_handles_null_output()
    {
        $handlerWithoutOutput = new AgentWebSocketHandler($this->mockTools, null);

        $reflection = new ReflectionClass($handlerWithoutOutput);
        $method = $reflection->getMethod('log');
        $method->setAccessible(true);

        // Should not throw exception with null output
        $method->invoke($handlerWithoutOutput, 'Test message');

        $this->assertTrue(true); // Test passes if no exception thrown
    }
}
