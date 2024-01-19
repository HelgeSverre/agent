<?php

namespace Tests;

use App\Providers\AppServiceProvider;
use Dotenv\Dotenv;
use Orchestra\Testbench\TestCase as Orchestra;
use Webklex\IMAP\Providers\LaravelServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AppServiceProvider::class,
            LaravelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Load .env.test into the environment.
        if (file_exists(dirname(__DIR__).'/.env')) {
            (Dotenv::createImmutable(dirname(__DIR__), '.env'))->load();
        }

        $app->useEnvironmentPath(__DIR__.'/..');

    }
}
