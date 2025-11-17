<?php

namespace AichaDigital\Larabill\Tests\DevelopTest;

use AichaDigital\Larabill\Tests\TestCase as BaseTestCase;

/**
 * Strategy 2: Load from BOTH directories sequentially
 * - First: tests/Database/migrations (old tables)
 * - Then: database/migrations (new v0.4.0 tables)
 */
class Strategy2TestCase extends BaseTestCase
{
    protected function defineDatabaseMigrations()
    {
        // Load old test migrations first
        $this->loadMigrationsFrom(__DIR__.'/../Database/migrations');
        
        // Then load package migrations (v0.4.0)
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
