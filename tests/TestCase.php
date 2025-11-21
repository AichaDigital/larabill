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
        // Load ALL migrations from database/migrations (includes test users now)
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load stub migrations (v0.4.1 ROI/VAT tables)
        $this->loadStubMigrations();
    }

    /**
     * Load stub migrations for testing (v0.4.1 ROI/VAT tables)
     */
    protected function loadStubMigrations(): void
    {
        $stubPath = __DIR__.'/../database/migrations';
        $stubs = [
            'create_country_vat_rates_table.php.stub',
            'create_vat_categories_table.php.stub',
            'create_eu_sales_thresholds_table.php.stub',
            'create_roi_queries_table.php.stub',
            'create_user_roi_verifications_table.php.stub',
        ];

        foreach ($stubs as $stub) {
            $stubFile = $stubPath.'/'.$stub;
            if (file_exists($stubFile)) {
                // Include and run the migration
                $migration = include $stubFile;
                $migration->up();
            }
        }
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
