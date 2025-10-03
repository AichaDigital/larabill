<?php

namespace AichaDigital\Larabill;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use AichaDigital\Larabill\Commands\LarabillCommand;

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
            ->hasMigration('create_larabill_table')
            ->hasCommand(LarabillCommand::class);
    }
}
