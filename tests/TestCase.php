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
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
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
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Load package configuration
        $app['config']->set('larabill', require __DIR__.'/../config/larabill.php');
    }

    /**
     * Create test users for testing purposes
     */
    protected function createTestUsers(): void
    {
        TestUser::create([
            'id' => 1,
            'name' => 'Test User 1',
            'email' => 'user1@test.com',
            'password' => bcrypt('password'),
        ]);

        TestUser::create([
            'id' => 2,
            'name' => 'Test User 2',
            'email' => 'user2@test.com',
            'password' => bcrypt('password'),
        ]);

        TestUser::create([
            'id' => 3,
            'name' => 'Test User 3',
            'email' => 'user3@test.com',
            'password' => bcrypt('password'),
        ]);
    }
}
