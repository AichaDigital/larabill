<?php

namespace AichaDigital\Larabill;

// Commands removed as they don't exist yet
use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Events\{
    RecurringBillingCompleted,
    RecurringBillingFailed,
    RecurringInvoiceGenerated
};
use AichaDigital\Larabill\Listeners\{
    AlertBillingFailure,
    LogBillingSummary,
    SendInvoiceNotification
};
use AichaDigital\Larabill\Services\TaxCalculation\VatCalculationStrategy;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\{Package, PackageServiceProvider};

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
        
        // Register install command manually (package not built with Spatie skeleton)
        if ($this->app->runningInConsole()) {
            $this->commands([
                \AichaDigital\Larabill\Console\LarabillInstallCommand::class,
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
            ->hasCommand(\AichaDigital\Larabill\Console\DetectUserIdTypeCommand::class);

        // Note: Migrations are published via `php artisan larabill:install`
        // This ensures correct order and avoids FK errors
    }
}
