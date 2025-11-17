<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\DataTransferObjects\{BillingDetails, InvoiceItemMetadata, SourceReference};
use AichaDigital\Larabill\Enums\{BillingFrequency, InvoiceStatus};
use AichaDigital\Larabill\Events\{
    RecurringBillingCompleted,
    RecurringBillingFailed,
    RecurringInvoiceGenerated
};
use AichaDigital\Larabill\Models\{Article, ArticleServiceStatus, Invoice, InvoiceItem};
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Recurring Billing Service
 *
 * Handles all recurring billing logic including:
 * - Finding services due for billing
 * - Generating invoices with proper periods
 * - Respecting days_in_advance configuration
 * - Using addMonths/addYears for temporal calculations
 */
final class RecurringBillingService
{
    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * Process recurring billing for services due on given date
     *
     * @param  Carbon  $date  Processing date
     * @param  bool  $dryRun  If true, simulates without creating invoices
     * @return array{processed: int, skipped: int, failed: int, invoices: array, errors: array}
     */
    public function processRecurringBilling(Carbon $date, bool $dryRun = false): array
    {
        $servicesInWindow = $this->getServicesInBillingWindow($date);
        $servicesDue      = $servicesInWindow->filter(fn ($service) => $this->shouldGenerateInvoice($service, $date));

        $results = [
            'processed' => 0,
            'skipped'   => $servicesInWindow->count() - $servicesDue->count(),
            'failed'    => 0,
            'invoices'  => [],
            'errors'    => [],
        ];

        foreach ($servicesDue as $service) {
            try {
                if (! $dryRun) {
                    $invoice               = $this->createInvoiceForService($service, $date);
                    $results['invoices'][] = $invoice->id;

                    // Update next billing date using addMonths/addYears
                    $this->updateNextBillingDate($service);

                    // Dispatch invoice generated event
                    RecurringInvoiceGenerated::dispatch($invoice, $service);
                }

                $results['processed']++;

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'service_id'  => $service->id,
                    'customer_id' => $service->customer_id,
                    'article_id'  => $service->article_id,
                    'error'       => $e->getMessage(),
                ];

                // Dispatch billing failed event
                RecurringBillingFailed::dispatch($service, $e, [
                    'billing_date' => $date->toDateString(),
                ]);

                // Log error for monitoring
                Log::error('Recurring billing failed', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
            }
        }

        // Dispatch billing completed event
        RecurringBillingCompleted::dispatch($date, $results, $dryRun);

        return $results;
    }

    /**
     * Get services that are within the billing window
     *
     * Returns all active services with next_billing_date within the days_in_advance window.
     * Uses a conservative approach: fetches services within the global window + buffer
     * to account for article-specific overrides.
     */
    protected function getServicesInBillingWindow(Carbon $date): Collection
    {
        $globalDays = config('larabill.recurring_billing.days_in_advance', 7);

        // Use a larger window to catch services with article-specific days_in_advance
        // We'll filter them properly in shouldGenerateInvoice()
        $bufferDays = 30; // Conservative buffer to catch article-specific overrides
        $windowEnd  = $date->copy()->addDays($bufferDays)->toDateString();

        // Get all active services with billing dates within the extended window
        return ArticleServiceStatus::query()
            ->with(['article', 'customer', 'currentOverride'])
            ->active()
            ->whereNotNull('next_billing_date')
            ->whereRaw('DATE(next_billing_date) <= ?', [$windowEnd])
            ->get();
    }

    /**
     * Get services that are due for billing on given date (DEPRECATED)
     *
     * @deprecated Use getServicesInBillingWindow() and filter manually
     * @see getServicesInBillingWindow()
     */
    protected function getServicesDueForBilling(Carbon $date): Collection
    {
        return $this->getServicesInBillingWindow($date)
            ->filter(fn ($service) => $this->shouldGenerateInvoice($service, $date));
    }

    /**
     * Check if invoice should be generated based on days_in_advance
     *
     * Uses article-specific days_in_advance if set, otherwise uses global config
     */
    protected function shouldGenerateInvoice(ArticleServiceStatus $service, Carbon $date): bool
    {
        // Get days in advance (article-specific or global)
        $daysInAdvance = $service->article->billing_days_in_advance
            ?? config('larabill.recurring_billing.days_in_advance', 7);

        // Calculate the date when we should start generating the invoice
        // If next_billing_date is 2024-10-26 and daysInAdvance is 7,
        // then generateDate is 2024-10-19 (we start generating 7 days before)
        $generateDate = $service->next_billing_date->copy()->subDays($daysInAdvance);

        // Compare dates as strings to avoid time component issues
        return $date->toDateString() >= $generateDate->toDateString();
    }

    /**
     * Create invoice for a recurring service
     */
    protected function createInvoiceForService(ArticleServiceStatus $service, Carbon $date): Invoice
    {
        $article  = $service->article;
        $customer = $service->customer;

        // Calculate billing period
        $periodStart = $service->next_billing_date;
        $periodEnd   = $this->calculatePeriodEnd($service);

        // Get effective pricing (with customer overrides)
        $pricingDetails = $this->pricingService->createPricingDetails(
            $article,
            $customer->id
        );

        // Calculate next billing date (for metadata)
        $nextBillingDate = $this->calculateNextBillingDate($service);

        // Create invoice
        $invoiceNumber = $this->generateInvoiceNumber();

        $invoice = Invoice::create([
            'user_id'        => $customer->id,
            'fiscal_number'  => $invoiceNumber['fiscal_number'],
            'prefix'         => $invoiceNumber['prefix'],
            'serie'          => $invoiceNumber['serie'],
            'series_number'  => $invoiceNumber['series_number'],
            'fiscal_year'    => $invoiceNumber['fiscal_year'],
            'invoice_date'   => $date,
            'due_date'       => $date->copy()->addDays(config('larabill.recurring_billing.payment_terms_days', 15)),
            'status'         => InvoiceStatus::SENT,
            'taxable_amount' => $pricingDetails->appliedPrice,
            'tax_amount'     => 0, // TODO: Calculate tax
            'total_amount'   => $pricingDetails->appliedPrice,
            'metadata'       => [
                'recurring_billing' => true,
                'service_id'        => $service->id,
                'article_id'        => $article->id,
            ],
        ]);

        // Create invoice item with comprehensive metadata
        $metadata = new InvoiceItemMetadata(
            sourceReference: new SourceReference(
                type: 'article_service',
                articleId: $article->id,
                serviceStatusId: $service->id,
                instanceIdentifier: $service->instance_identifier
            ),
            pricingDetails: $pricingDetails,
            billingDetails: new BillingDetails(
                billingCycle: $article->billing_frequency->value,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                nextBillingDate: $nextBillingDate,
                billingInterval: $article->billing_interval
            )
        );

        InvoiceItem::create([
            'invoice_id'        => $invoice->id,
            'item_type'         => $article->item_type,
            'description'       => $article->name,
            'quantity'          => 1,
            'unit_price'        => $pricingDetails->appliedPrice,
            'service_date_from' => $periodStart,
            'service_date_to'   => $periodEnd,
            'metadata'          => $metadata->toArray(),
        ]);

        return $invoice;
    }

    /**
     * Calculate period end based on billing frequency
     *
     * Uses addMonthsNoOverflow to prevent day overflow issues
     */
    protected function calculatePeriodEnd(ArticleServiceStatus $service): Carbon
    {
        $start = $service->next_billing_date->copy();

        return match ($service->article->billing_frequency) {
            BillingFrequency::MONTHLY   => $start->addMonthsNoOverflow($service->article->billing_interval)->subDay(),
            BillingFrequency::QUARTERLY => $start->addMonthsNoOverflow(3 * $service->article->billing_interval)->subDay(),
            BillingFrequency::YEARLY    => $start->addYearsNoOverflow($service->article->billing_interval)->subDay(),
            default                     => $start->addMonthNoOverflow()->subDay(),
        };
    }

    /**
     * Calculate next billing date using addMonths/addYears
     *
     * Uses addMonthsNoOverflow to prevent day overflow (Jan 31 + 1 month = Feb 28/29, not Mar 2/3)
     */
    protected function calculateNextBillingDate(ArticleServiceStatus $service): Carbon
    {
        $current = $service->next_billing_date->copy();

        return match ($service->article->billing_frequency) {
            BillingFrequency::MONTHLY   => $current->addMonthsNoOverflow($service->article->billing_interval),
            BillingFrequency::QUARTERLY => $current->addMonthsNoOverflow(3 * $service->article->billing_interval),
            BillingFrequency::YEARLY    => $current->addYearsNoOverflow($service->article->billing_interval),
            default                     => $current->addMonthNoOverflow(),
        };
    }

    /**
     * Update service next billing date after invoice generation
     */
    protected function updateNextBillingDate(ArticleServiceStatus $service): void
    {
        $nextDate = $this->calculateNextBillingDate($service);

        $service->update([
            'next_billing_date' => $nextDate,
        ]);
    }

    /**
     * Generate invoice number
     *
     * TODO: Use proper InvoiceSeriesControl for correlative numbering
     *
     * @return array{fiscal_number: string, prefix: string, serie: int, series_number: int, fiscal_year: int}
     */
    protected function generateInvoiceNumber(): array
    {
        // For now, use simple sequential numbering
        // In production, this should use InvoiceSeriesControl
        $lastInvoice = Invoice::query()
            ->where('serie', 1) // Regular invoices
            ->orderByDesc('series_number')
            ->first();

        $seriesNumber = ($lastInvoice->series_number ?? 0) + 1;
        $fiscalYear   = now()->year;
        $fiscalNumber = sprintf('FAC-%d-%06d', $fiscalYear, $seriesNumber);

        return [
            'fiscal_number' => $fiscalNumber,
            'prefix'        => 'FAC',
            'serie'         => 1,
            'series_number' => $seriesNumber,
            'fiscal_year'   => $fiscalYear,
        ];
    }
}
