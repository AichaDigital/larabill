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

        // Register Filament resources (v1.0 - will be extracted to plugin in v2.0)
        $this->registerFilamentResources();

        // Load migrations automatically ONLY in CI/testing (not in local with published migrations)
        // In production, use `php artisan larabill:install` for controlled publishing
        // In local dev with symlinks, migrations are published in database/migrations/
        if ($this->app->runningInConsole() &&
            $this->app->environment('testing') &&
            ! $this->migrationsArePublished()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Register install command manually (package not built with Spatie skeleton)
        if ($this->app->runningInConsole()) {
            $this->commands([
                \AichaDigital\Larabill\Console\LarabillInstallCommand::class,
            ]);
        }
    }

    /**
     * Check if migrations are already published to app
     */
    protected function migrationsArePublished(): bool
    {
        return file_exists(database_path('migrations/2025_11_22_092323_create_legal_entity_types_table.php'));
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

    /**
     * Register Filament resources (v1.0 only - extracted to plugin in v2.0)
     *
     * This method registers Larabill's Filament resources automatically
     * when Filament is installed and enabled in config.
     *
     * @note In v2.0, this will be moved to `aichadigital/larabill-filament` plugin
     */
    protected function registerFilamentResources(): void
    {
        // Only register if Filament is installed and enabled
        if (! $this->app->bound('filament') || ! config('larabill.filament.enabled', true)) {
            return;
        }

        // Register Livewire components for Filament resources
        // This ensures Filament can discover our resources
        if (class_exists(\Livewire\Livewire::class)) {
            // Resources will auto-discover via Filament's resource discovery
            // No manual registration needed if following Filament conventions
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

        // Note: Migrations load automatically via loadMigrationsFrom() in boot()
        // In production, use `php artisan larabill:install` for controlled publishing
    }
}
