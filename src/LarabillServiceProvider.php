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
            ->hasMigrations([
                // Core tables (orden crítico para FK)
                '2019_08_19_000000_create_users_table',
                '2025_01_01_000000_create_test_users_table',
                '2025_01_25_000001_create_legal_entity_types_table',
                '2025_01_25_000002_create_issuer_config_table',
                '2025_01_25_000003_create_issuer_tax_profiles_table',
                '2025_01_25_000004_create_customers_table',
                '2025_01_25_000005_create_customer_tax_profiles_table',
                '2024_12_01_000005_create_user_tax_infos_table',
                '2024_12_01_000002_create_tax_categories_table',
                '2025_01_20_000001_create_articles_table',
                '2025_01_20_000002_create_article_overrides_table',
                '2025_01_20_000003_create_article_service_status_table',
                '2024_12_01_000000_create_tax_rates_table',
                '2024_12_01_000001_create_tax_groups_table',
                '2024_12_01_000002_create_tax_group_tax_rate_table',
                '2024_12_01_000002_create_invoice_series_control_table',
                '2024_12_01_000003_create_invoices_table',
                '2024_12_01_0004_create_invoice_items_table',
                '2025_01_25_000006_create_commissions_table',
                '2024_12_01_000007_create_vat_verifications_table',
                '2024_12_01_000008_create_company_fiscal_configs_table',
                '2025_01_04_190001_create_invoice_templates_table',
                '2025_01_04_190002_create_company_template_settings_table',
                '2025_01_25_000007_add_v040_fields_to_invoices_table',
                '2025_01_25_000008_add_converted_fields_to_invoices_table',
            ])
            ->hasCommand(\AichaDigital\Larabill\Console\DetectUserIdTypeCommand::class);

        // Note: In production, use `php artisan larabill:install` to publish
        // migrations with correct naming. In CI/testing, migrations load automatically.
    }
}
