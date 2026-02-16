<?php

namespace AichaDigital\Larabill\Tests;

use AichaDigital\Larabill\LarabillServiceProvider;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
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
    }

    protected function getPackageProviders($app)
    {
        return [
            LarabillServiceProvider::class,
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

        // Load package configuration
        $app['config']->set('larabill', require __DIR__.'/../config/larabill.php');

        // Override user model for tests
        $app['config']->set('larabill.user_model', TestUser::class);

        // Use string UUID for tests (migrations use $table->uuid() which creates char(36))
        // Production apps can configure uuid_binary with proper binary(16) migrations
        $app['config']->set('larabill.user_id_type', 'uuid');
    }

    /**
     * Create test users for testing purposes
     */
    protected function createTestUsers(): void
    {
        TestUser::create([
            'id'       => 1,
            'name'     => 'Test User 1',
            'email'    => 'user1@test.com',
            'password' => bcrypt('password'),
        ]);

        TestUser::create([
            'id'       => 2,
            'name'     => 'Test User 2',
            'email'    => 'user2@test.com',
            'password' => bcrypt('password'),
        ]);

        TestUser::create([
            'id'       => 3,
            'name'     => 'Test User 3',
            'email'    => 'user3@test.com',
            'password' => bcrypt('password'),
        ]);
    }
}
