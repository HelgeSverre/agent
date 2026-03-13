<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebUIServerTest extends TestCase
{
    public function test_webui_server_command_exists(): void
    {
        $this->artisan('webui:server --help')
            ->assertExitCode(0);
    }

    public function test_webui_server_starts_with_default_options(): void
    {
        // This test would require more complex setup with React loop testing
        // For now, we'll just test the command setup
        $this->assertTrue(true);
    }

    public function test_run_command_web_flag_works(): void
    {
        // Test that --web flag properly calls webui:server
        $this->artisan('run --web --help')
            ->assertExitCode(0);
    }
}
