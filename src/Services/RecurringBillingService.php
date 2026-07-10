<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\DataTransferObjects\BillingDetails;
use AichaDigital\Larabill\DataTransferObjects\InvoiceItemMetadata;
use AichaDigital\Larabill\DataTransferObjects\SourceReference;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Events\RecurringBillingCompleted;
use AichaDigital\Larabill\Events\RecurringBillingFailed;
use AichaDigital\Larabill\Events\RecurringInvoiceGenerated;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    protected InvoiceNumberingService $invoiceNumbering;

    public function __construct(
        protected PricingService $pricingService,
        ?InvoiceNumberingService $invoiceNumbering = null
    ) {
        $this->invoiceNumbering = $invoiceNumbering ?? app(InvoiceNumberingService::class);
    }

    /**
     * Process recurring billing for services due on given date
     *
     * @param  Carbon  $date  Processing date
     * @param  bool  $dryRun  If true, simulates without creating invoices
     * @return array{processed: int, skipped: int, failed: int, invoices: list<int|string>, errors: list<array<string, mixed>>}
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
     *
     * @return Collection<int, ArticleServiceStatus>
     */
    protected function getServicesInBillingWindow(Carbon $date): Collection
    {
        // Use a larger window to catch services with article-specific days_in_advance
        // We'll filter them properly in shouldGenerateInvoice()
        // Note: config days_in_advance (default 7) is checked per-article in shouldGenerateInvoice()
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
     *
     * @return Collection<int, ArticleServiceStatus>
     */
    protected function getServicesDueForBilling(Carbon $date): Collection
    {
        return $this->getServicesInBillingWindow($date)
            ->filter(fn ($service) => $this->shouldGenerateInvoice($service, $date));
    }

    /**
     * Check if invoice should be generated based on days_in_advance
     *
     * Uses ArticlePrice-specific days_in_advance if set, otherwise uses global config
     */
    protected function shouldGenerateInvoice(ArticleServiceStatus $service, Carbon $date): bool
    {
        $nextBillingDate = $service->next_billing_date;
        if ($nextBillingDate === null) {
            return false;
        }

        // Get days in advance from ArticlePrice for the service's frequency, or global config
        $daysInAdvance = $service->article->getBillingDaysInAdvanceFor($service->billing_frequency)
            ?? config('larabill.recurring_billing.days_in_advance', 7);

        // Calculate the date when we should start generating the invoice
        // If next_billing_date is 2024-10-26 and daysInAdvance is 7,
        // then generateDate is 2024-10-19 (we start generating 7 days before)
        $generateDate = $nextBillingDate->copy()->subDays($daysInAdvance);

        // Compare dates as strings to avoid time component issues
        return $date->toDateString() >= $generateDate->toDateString();
    }

    /**
     * Create invoice for a recurring service
     *
     * Uses database transaction for atomicity.
     * Includes idempotency check to prevent duplicate invoices.
     */
    protected function createInvoiceForService(ArticleServiceStatus $service, Carbon $date): Invoice
    {
        return DB::transaction(function () use ($service, $date): Invoice {
            $periodStart = $service->next_billing_date;
            if ($periodStart === null) {
                throw new \RuntimeException("Cannot bill service {$service->id} without a next_billing_date.");
            }

            $customer = $service->customer;
            if ($customer === null) {
                throw new \RuntimeException("Cannot bill service {$service->id} without a customer.");
            }

            // Idempotency check: look for existing invoice item for this service + period
            $existingItem = InvoiceItem::query()
                ->whereRaw(
                    "json_extract(metadata, '$.source_reference.service_status_id') = ?",
                    [$service->id]
                )
                ->whereDate('service_date_from', $periodStart->toDateString())
                ->first();

            if ($existingItem && $existingItem->invoice instanceof Invoice) {
                // Return existing invoice (idempotent)
                return $existingItem->invoice;
            }

            $article = $service->article;

            // Calculate billing period
            $periodEnd = $this->calculatePeriodEnd($service);

            // Get effective pricing (with customer overrides) using service's billing frequency
            $pricingDetails = $this->pricingService->createPricingDetailsForService($service);

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
                'taxable_amount' => FixedDecimal::ofUnscaled((int) $pricingDetails->appliedPrice, 2),
                'total_amount'   => FixedDecimal::ofUnscaled((int) $pricingDetails->appliedPrice, 2),
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
                    billingCycle: $service->billing_frequency->label(),
                    periodStart: $periodStart,
                    periodEnd: $periodEnd,
                    nextBillingDate: $nextBillingDate,
                    billingInterval: 1
                )
            );

            InvoiceItem::create([
                'invoice_id'        => $invoice->id,
                'item_type'         => $article->item_type,
                'description'       => $article->name,
                'quantity'          => FixedDecimal::ofUnscaled(1, 2),
                'unit_price'        => FixedDecimal::ofUnscaled((int) $pricingDetails->appliedPrice, 2),
                'service_date_from' => $periodStart,
                'service_date_to'   => $periodEnd,
                'metadata'          => $metadata->toArray(),
            ]);

            return $invoice;
        });
    }

    /**
     * Calculate period end based on service billing frequency
     *
     * Uses the BillingFrequency enum's addToDate method and subtracts 1 day
     */
    protected function calculatePeriodEnd(ArticleServiceStatus $service): Carbon
    {
        $next = $service->next_billing_date;
        if ($next === null) {
            throw new \RuntimeException("Cannot compute the period end for service {$service->id} without a next_billing_date.");
        }

        $start = $next->copy();

        // Use the enum's addToDate method which handles all frequencies
        return $service->billing_frequency->addToDate($start)->subDay();
    }

    /**
     * Calculate next billing date using the service's billing frequency
     *
     * Delegates to the BillingFrequency enum's addToDate method
     */
    protected function calculateNextBillingDate(ArticleServiceStatus $service): Carbon
    {
        return $service->calculateNextBillingDate();
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
     * Delegates to the single numbering owner (AID-390): fiscal_number,
     * prefix, series_number and fiscal_year all derive from the SAME
     * InvoiceNumber, on the GLOBAL issuer scope — recurring invoices are
     * never scoped by the billed customer (ADR-003).
     *
     * @return array{fiscal_number: string, prefix: string, serie: int, series_number: int, fiscal_year: int}
     */
    protected function generateInvoiceNumber(): array
    {
        $number = $this->invoiceNumbering->generateNumber(
            'FAC',
            InvoiceSerieType::INVOICE->value
        );

        return [
            'fiscal_number' => $number->formatted,
            'prefix'        => $number->prefix,
            'serie'         => InvoiceSerieType::INVOICE->value,
            'series_number' => $number->seriesNumber,
            'fiscal_year'   => $number->fiscalYear,
        ];
    }
}
