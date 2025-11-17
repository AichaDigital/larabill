<?php

namespace AichaDigital\Larabill\Tests\DevelopTest;

use AichaDigital\Larabill\LarabillServiceProvider;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Strategy 4: DON'T register ServiceProvider, load migrations manually
 * This prevents double-loading from ServiceProvider + defineDatabaseMigrations
 */
class Strategy4TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'AichaDigital\\Larabill\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        try {
            $this->createTestUsers();
        } catch (\Exception $e) {
            // Database not ready
        }
    }

    protected function defineDatabaseMigrations()
    {
        // Load ALL migrations from database/migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    // DON'T register ServiceProvider to avoid double migration loading
    protected function getPackageProviders($app)
    {
        return [];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('larabill', require __DIR__.'/../../config/larabill.php');
        $app['config']->set('larabill.user_model', TestUser::class);
        
        // Manually register services WITHOUT migrations
        $app->singleton('larabill', fn () => new \stdClass());
    }

    protected function createTestUsers(): void
    {
        TestUser::create([
            'id'       => 1,
            'name'     => 'Test User 1',
            'email'    => 'user1@test.com',
            'password' => bcrypt('password'),
        ]);
    }
}
