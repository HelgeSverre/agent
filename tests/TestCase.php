<?php

namespace Tests;

use App\Providers\AppServiceProvider;
use Dotenv\Dotenv;
use OpenAI\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AppServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Load .env.test into the environment.
        if (file_exists(dirname(__DIR__).'/.env')) {
            (Dotenv::createImmutable(dirname(__DIR__), '.env'))->load();
        }

        $app->useEnvironmentPath(__DIR__.'/..');

        // Configure OpenAI
        config()->set('openai.api_key', env('OPENAI_API_KEY', 'test-key'));
        config()->set('openai.organization', env('OPENAI_ORGANIZATION'));
    }
}
