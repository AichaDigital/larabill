<?php

declare(strict_types=1);

namespace AichaDigital\Larabill;

use AichaDigital\Larabill\Console\LarabillInstallCommand;
use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Events\RecurringBillingCompleted;
use AichaDigital\Larabill\Events\RecurringBillingFailed;
use AichaDigital\Larabill\Events\RecurringInvoiceGenerated;
use AichaDigital\Larabill\Listeners\AlertBillingFailure;
use AichaDigital\Larabill\Listeners\LogBillingSummary;
use AichaDigital\Larabill\Listeners\SendInvoiceNotification;
use AichaDigital\Larabill\Services\TaxCalculation\VatCalculationStrategy;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LarabillServiceProvider extends PackageServiceProvider
{
    /**
     * Event listeners for recurring billing
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected array $listen = [
        RecurringInvoiceGenerated::class => [
            SendInvoiceNotification::class,
        ],
        RecurringBillingCompleted::class => [
            LogBillingSummary::class,
        ],
        RecurringBillingFailed::class => [
            AlertBillingFailure::class,
        ],
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(TaxCalculationStrategy::class, VatCalculationStrategy::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Register event listeners
        $this->registerEventListeners();

        // Load migrations automatically in non-production environments
        // In production, migrations should be explicitly published via larabill:install
        if ($this->app->runningInConsole() && ! $this->app->environment('production')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Register install command manually (package not built with Spatie skeleton)
        if ($this->app->runningInConsole()) {
            $this->commands([
                LarabillInstallCommand::class,
            ]);
        }
    }

    /**
     * Register package event listeners
     */
    protected function registerEventListeners(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

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
            ->hasTranslations();

        // Note: Migrations load automatically via loadMigrationsFrom() in boot()
        // In production, use `php artisan larabill:install` for controlled publishing
    }
}
