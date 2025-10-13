<?php

namespace AichaDigital\Larabill;

// Commands removed as they don't exist yet
use Spatie\LaravelPackageTools\{Package, PackageServiceProvider};

class LarabillServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('larabill')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommand(\AichaDigital\Larabill\Console\DetectUserIdTypeCommand::class)
            ->hasMigrations([
                // Core tables
                'create_invoices_table',
                'create_invoice_items_table',
                'create_user_tax_infos_table',
                'create_tax_rates_table',
                'create_vat_verifications_table',
                'create_company_fiscal_configs_table',
                // Template system
                'create_invoice_templates_table',
                'create_company_template_settings_table',
            ]);
        // Note: Without ->runsMigrations(), migrations are only published
        // Users must manually run: php artisan migrate
        // This gives full control over billing schema changes
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        // Register the main Larabill class as a singleton
        $this->app->singleton(Larabill::class, function ($app) {
            return new Larabill;
        });

        // Register the facade alias
        $this->app->alias(Larabill::class, 'larabill');
    }
}
