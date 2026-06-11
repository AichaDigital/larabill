<?php

namespace AichaDigital\Larabill\Tests;

use AichaDigital\Larabill\LarabillServiceProvider;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\LaraVerifactu\LaraVerifactuServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * Deterministic UUID v7 fixtures for the three primary test users.
     *
     * Format: timestamp prefix `0194a000-0000` + version=7 nibble + variant=8
     * + sequential tail. Pass `Uuid::isValid()` and stay readable in failing
     * test diffs. Used as drop-in replacements for the legacy `'user_id' => 1/2/3`
     * fixtures across the suite (see ADR-006, SPEC §3.2).
     */
    public const USER_UUID_1 = '0194a000-0000-7000-8000-000000000001';

    public const USER_UUID_2 = '0194a000-0000-7000-8000-000000000002';

    public const USER_UUID_3 = '0194a000-0000-7000-8000-000000000003';

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AichaDigital\\Larabill\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Create test users for testing only if database is ready
        try {
            $this->createTestUsers();
        } catch (\Exception $e) {
            // Database not ready, skip user creation
        }
    }

    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations()
    {
        // Load test-only migrations first (users table for FK dependencies)
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // Load package migrations (includes all .php timestamped files)
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load lara-verifactu migrations (its provider only publishes them);
        // needed by the InvoiceVerifactuService bridge tests.
        $this->loadMigrationsFrom(__DIR__.'/../vendor/aichadigital/lara-verifactu/database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            LarabillServiceProvider::class,
            LaraVerifactuServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Set APP_KEY for encryption (required by InvoiceService snapshots)
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Force in-memory array cache so Cache::flush() never tries to truncate a
        // missing `cache` table when tests run in arbitrary order.
        config()->set('cache.default', 'array');

        // Load package configuration
        $app['config']->set('larabill', require __DIR__.'/../config/larabill.php');

        // Override user model for tests
        $app['config']->set('larabill.user_model', TestUser::class);
    }

    /**
     * Create the three deterministic test users used as fixtures across the suite.
     *
     * IDs come from the USER_UUID_* class constants so that tests can refer to
     * the same fixture by constant name (e.g. `'user_id' => self::USER_UUID_1`).
     */
    protected function createTestUsers(): void
    {
        TestUser::create([
            'id'       => self::USER_UUID_1,
            'name'     => 'Test User 1',
            'email'    => 'user1@test.com',
            'password' => bcrypt('password'),
        ]);

        TestUser::create([
            'id'       => self::USER_UUID_2,
            'name'     => 'Test User 2',
            'email'    => 'user2@test.com',
            'password' => bcrypt('password'),
        ]);

        TestUser::create([
            'id'       => self::USER_UUID_3,
            'name'     => 'Test User 3',
            'email'    => 'user3@test.com',
            'password' => bcrypt('password'),
        ]);
    }
}
