<?php

namespace AichaDigital\Larabill\Tests\DevelopTest;

use AichaDigital\Larabill\Tests\TestCase as BaseTestCase;

/**
 * Strategy 3: Use ONLY database/migrations (copy users migrations there)
 * This avoids calling loadMigrationsFrom() twice
 */
class Strategy3TestCase extends BaseTestCase
{
    protected function defineDatabaseMigrations()
    {
        // Load ALL migrations from a SINGLE directory
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
