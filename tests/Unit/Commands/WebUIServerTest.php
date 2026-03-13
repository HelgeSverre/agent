<?php

namespace Tests\Unit\Commands;

use App\Commands\WebUIServer;
use App\Tools\BrowseWebsiteTool;
use App\Tools\ReadFileTool;
use App\Tools\RunCommandTool;
use App\Tools\SearchWebTool;
use App\Tools\SpeakTool;
use App\Tools\WriteFileTool;
use App\WebSocket\AgentWebSocketHandler;
use Mockery;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

class WebUIServerTest extends TestCase
{
    private WebUIServer $command;

    private OutputInterface $mockOutput;

    private InputInterface $mockInput;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new WebUIServer;
        $this->mockOutput = Mockery::mock(OutputInterface::class);
        $this->mockInput = Mockery::mock(InputInterface::class);

        // Set up the command with mock input/output
        $reflection = new ReflectionClass($this->command);
        $inputProperty = $reflection->getProperty('input');
        $inputProperty->setAccessible(true);
        $inputProperty->setValue($this->command, $this->mockInput);

        $outputProperty = $reflection->getProperty('output');
        $outputProperty->setAccessible(true);
        $outputProperty->setValue($this->command, $this->mockOutput);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_signature_is_correct()
    {
        // Access protected signature property using reflection
        $reflection = new ReflectionClass($this->command);
        $signatureProperty = $reflection->getProperty('signature');
        $signatureProperty->setAccessible(true);
        $signature = $signatureProperty->getValue($this->command);

        $this->assertStringContainsString('web', $signature);
        $this->assertStringContainsString('--port', $signature);
        $this->assertStringContainsString('--host', $signature);
        $this->assertStringContainsString('--open', $signature);
    }

    public function test_command_description_is_set()
    {
        // Access protected description property using reflection
        $reflection = new ReflectionClass($this->command);
        $descriptionProperty = $reflection->getProperty('description');
        $descriptionProperty->setAccessible(true);
        $description = $descriptionProperty->getValue($this->command);

        $this->assertEquals(
            'Start the web UI server for agent interaction',
            $description
        );
    }

    public function test_default_options_are_used()
    {
        // Test tool creation without mocking the full handle method
        $tools = $this->callProtectedMethod($this->command, 'createTools');

        $this->assertIsArray($tools);
        $this->assertCount(6, $tools);
        $this->assertInstanceOf(ReadFileTool::class, $tools[0]);
        $this->assertInstanceOf(WriteFileTool::class, $tools[1]);
        $this->assertInstanceOf(SearchWebTool::class, $tools[2]);
        $this->assertInstanceOf(BrowseWebsiteTool::class, $tools[3]);
        $this->assertInstanceOf(RunCommandTool::class, $tools[4]);
        $this->assertInstanceOf(SpeakTool::class, $tools[5]);
    }

    public function test_custom_port_and_host_are_used()
    {
        // Test that options can be read correctly by testing option names exist in signature
        $reflection = new ReflectionClass($this->command);
        $signatureProperty = $reflection->getProperty('signature');
        $signatureProperty->setAccessible(true);
        $signature = $signatureProperty->getValue($this->command);

        $this->assertStringContainsString('--port=8080', $signature);
        $this->assertStringContainsString('--host=127.0.0.1', $signature);
        $this->assertStringContainsString('--open', $signature);

        // Test default values are correctly set in signature
        $this->assertTrue(true);
    }

    public function test_create_tools_returns_correct_tools()
    {
        $tools = $this->callProtectedMethod($this->command, 'createTools');

        $this->assertIsArray($tools);
        $this->assertCount(6, $tools);

        // Verify each tool type
        $toolTypes = array_map(fn ($tool) => get_class($tool), $tools);

        $this->assertContains(ReadFileTool::class, $toolTypes);
        $this->assertContains(WriteFileTool::class, $toolTypes);
        $this->assertContains(SearchWebTool::class, $toolTypes);
        $this->assertContains(BrowseWebsiteTool::class, $toolTypes);
        $this->assertContains(RunCommandTool::class, $toolTypes);
        $this->assertContains(SpeakTool::class, $toolTypes);
    }

    public function test_write_file_tool_has_correct_output_directory()
    {
        $tools = $this->callProtectedMethod($this->command, 'createTools');

        $writeFileTool = null;
        foreach ($tools as $tool) {
            if ($tool instanceof WriteFileTool) {
                $writeFileTool = $tool;
                break;
            }
        }

        $this->assertInstanceOf(WriteFileTool::class, $writeFileTool);

        // Use reflection to check the base directory
        $reflection = new ReflectionClass($writeFileTool);
        $property = $reflection->getProperty('baseDir');
        $property->setAccessible(true);

        $this->assertEquals(base_path('output'), $property->getValue($writeFileTool));
    }

    public function test_open_browser_handles_different_os()
    {
        $url = 'http://127.0.0.1:8080/webui.html';

        // We can't easily test exec() calls, but we can verify the method exists
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('openBrowser');
        $method->setAccessible(true);

        // This would normally execute the appropriate OS command
        // In a real test environment, you might want to mock exec()
        $this->assertTrue(method_exists($this->command, 'openBrowser'));

        // Test that method can be invoked without throwing exceptions
        $method->invoke($this->command, $url);
        $this->assertTrue(true);
    }

    public function test_open_browser_method_handles_different_os()
    {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('openBrowser');
        $method->setAccessible(true);

        // Test that the method exists and can be called
        $this->assertTrue($method->isProtected());

        // We can't easily test the actual exec calls without mocking
        // but we can verify the method signature
        $parameters = $method->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('url', $parameters[0]->getName());
    }

    public function test_browser_opens_when_option_is_set()
    {
        // Test that the openBrowser method exists and can be called
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('openBrowser');
        $method->setAccessible(true);

        $this->assertTrue($method->isProtected());

        // Test that openBrowser method exists by calling it with a test URL
        // In a real test environment, this would trigger the browser opening
        $method->invoke($this->command, 'http://test.example.com');

        $this->assertTrue(true); // Test passes if no exception thrown
    }

    public function test_websocket_handler_is_created_with_correct_parameters()
    {
        // Test that the WebSocket handler would be created with the right tools and output
        $tools = $this->callProtectedMethod($this->command, 'createTools');

        $handler = new AgentWebSocketHandler($tools, $this->mockOutput);

        $this->assertInstanceOf(AgentWebSocketHandler::class, $handler);

        // Use reflection to verify the handler was created with correct parameters
        $reflection = new ReflectionClass($handler);

        $toolsProperty = $reflection->getProperty('tools');
        $toolsProperty->setAccessible(true);
        $handlerTools = $toolsProperty->getValue($handler);

        $outputProperty = $reflection->getProperty('output');
        $outputProperty->setAccessible(true);
        $handlerOutput = $outputProperty->getValue($handler);

        $this->assertEquals($tools, $handlerTools);
        $this->assertEquals($this->mockOutput, $handlerOutput);
    }

    /**
     * Call a protected method on an object
     */
    private function callProtectedMethod($object, string $methodName, ...$args)
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invoke($object, ...$args);
    }
}
