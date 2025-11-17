<?php

namespace AichaDigital\Larabill\Tests\DevelopTest;

use AichaDigital\Larabill\Tests\TestCase as BaseTestCase;

/**
 * TestCase experimental para probar estrategias de carga de migraciones
 */
class ExperimentalTestCase extends BaseTestCase
{
    /**
     * Strategy 1: Load only from tests/Database/migrations (CURRENT - WORKS)
     */
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/migrations');
    }
}
