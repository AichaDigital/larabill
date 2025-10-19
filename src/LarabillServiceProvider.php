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
            ->hasCommand(\AichaDigital\Larabill\Console\DetectUserIdTypeCommand::class)
            ->hasMigrations([
                // Articles system (v0.3.4+)
                '2025_01_20_000001_create_articles_table',
                '2025_01_20_000002_create_article_overrides_table',
                '2025_01_20_000003_create_article_service_status_table',
                // Tax system (Configuration Layer) - v0.3.3 Agnostic Tax System
                '2024_12_01_000000_create_tax_rates_table',
                '2024_12_01_000001_create_tax_groups_table',
                '2024_12_01_000002_create_tax_group_tax_rate_table',
                // Invoice system (Immutable Record Layer)
                '2024_12_01_000003_create_invoices_table',
                '2024_12_01_0004_create_invoice_items_table',
                // Supporting tables
                '2024_12_01_000005_create_user_tax_infos_table',
                '2024_12_01_000006_create_tax_rates_table',
                '2024_12_01_000007_create_vat_verifications_table',
                '2024_12_01_000008_create_company_fiscal_configs_table',
                // Template system
                '2025_01_04_190001_create_invoice_templates_table',
                '2025_01_04_190002_create_company_template_settings_table',
            ]);
        // Note: Without ->runsMigrations(), migrations are only published
        // Users must manually run: php artisan migrate
        // This gives full control over billing schema changes
    }
}
