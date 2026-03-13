<?php

namespace Tests\Unit\WebSocket;

use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;
use App\WebSocket\AgentWebSocketHandler;
use Exception;
use Mockery;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use ReflectionClass;
use Symfony\Component\Console\Output\OutputInterface;

class AgentWebSocketHandlerSimpleTest extends TestCase
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
            ->twice(); // Connection message + context message

        $this->handler->onOpen($this->mockConnection);

        // Verify client was attached
        $reflection = new ReflectionClass($this->handler);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clients = $clientsProperty->getValue($this->handler);

        $this->assertTrue($clients->contains($this->mockConnection));
    }

    public function test_on_message_handles_status_request()
    {
        $message = json_encode(['type' => 'status']);

        $this->mockOutput->shouldReceive('writeln')->once();
        $this->mockConnection->shouldReceive('send')->once();

        $this->handler->onMessage($this->mockConnection, $message);

        $this->assertTrue(true); // Test passes if no exceptions thrown
    }

    public function test_on_message_handles_invalid_json()
    {
        $invalidMessage = 'invalid json {';

        $this->mockOutput->shouldReceive('writeln')->once();
        $this->mockConnection->shouldReceive('send')->once();

        $this->handler->onMessage($this->mockConnection, $invalidMessage);

        $this->assertTrue(true); // Test passes if no exceptions thrown
    }

    public function test_on_close_detaches_client()
    {
        // First attach the client
        $this->mockOutput->shouldReceive('writeln')->atLeast()->once();
        $this->mockConnection->shouldReceive('send')->atLeast()->once();
        $this->handler->onOpen($this->mockConnection);

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

        $this->mockConnection->shouldReceive('send')->once();
        $this->mockConnection->shouldReceive('close')->once();

        $this->handler->onError($this->mockConnection, $exception);

        $this->assertTrue(true); // Test passes if no exceptions thrown
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

        $this->assertTrue(true); // Test passes if no exceptions thrown
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

        $this->assertTrue(true); // Test passes if no exceptions thrown
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
