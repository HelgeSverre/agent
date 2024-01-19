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

        config()->set('imap.accounts.default.host', env('IMAP_HOST'));
        config()->set('imap.accounts.default.port', env('IMAP_PORT'));
        config()->set('imap.accounts.default.encryption', env('IMAP_ENCRYPTION'));
        config()->set('imap.accounts.default.validate_cert', env('IMAP_VALIDATE_CERT'));
        config()->set('imap.accounts.default.username', env('IMAP_USERNAME'));
        config()->set('imap.accounts.default.password', env('IMAP_PASSWORD'));
        config()->set('imap.accounts.default.protocol', env('IMAP_PROTOCOL'));
    }
}
