<?php

namespace AichaDigital\Larabill;

use AichaDigital\Larabill\Commands\LarabillCommand;
use AichaDigital\Larabill\Commands\TestVatApisCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
            ])
            ->runsMigrations()
            ->hasCommands([
                LarabillCommand::class,
                TestVatApisCommand::class,
            ]);
    }
}
