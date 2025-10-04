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
            ->hasMigrations([
                'create_invoices_table',
                'create_invoice_items_table',
                'create_user_tax_infos_table',
                'create_tax_rates_table',
                'create_vat_verifications_table',
                'create_company_fiscal_configs_table',
                'add_oss_and_roi_fields_to_company_fiscal_configs',
                'add_is_roi_taxed_to_invoices_table',
            ])
            ->runsMigrations();
            // Commands removed as they don't exist yet
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        // Register the main Larabill class as a singleton
        $this->app->singleton(Larabill::class, function ($app) {
            return new Larabill();
        });

        // Register the facade alias
        $this->app->alias(Larabill::class, 'larabill');
    }
}
