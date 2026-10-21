<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Safety net: RefreshDatabase runs migrate:fresh. If a cached config
        // (bootstrap/cache/config.php, from `optimize` / `config:cache`)
        // overrides phpunit.xml's sqlite :memory: settings, the tests would
        // wipe the real database instead — this happened to the MySQL dev
        // DB on 2026-09-28. Refuse to run against anything but in-memory
        // SQLite; `composer test` / `php artisan optimize:clear` fixes it.
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(
                "Refusing to run tests against [{$connection}:{$database}]. Tests only run on in-memory SQLite. ".
                'A cached config is probably overriding phpunit.xml: run `php artisan optimize:clear` (or use `composer test`).'
            );
        }

        return $app;
    }
}
