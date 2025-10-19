<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\DataTransferObjects\{BillingDetails, InvoiceItemMetadata, SourceReference};
use AichaDigital\Larabill\Enums\{BillingFrequency, InvoiceStatus};
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
        $services = $this->getServicesDueForBilling($date);

        $results = [
            'processed' => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'invoices'  => [],
            'errors'    => [],
        ];

        foreach ($services as $service) {
            try {
                // Check if should generate invoice based on days_in_advance
                if (! $this->shouldGenerateInvoice($service, $date)) {
                    $results['skipped']++;

                    continue;
                }

                if (! $dryRun) {
                    $invoice               = $this->createInvoiceForService($service, $date);
                    $results['invoices'][] = $invoice->id;

                    // Update next billing date using addMonths/addYears
                    $this->updateNextBillingDate($service);
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

                // Log error for monitoring
                Log::error('Recurring billing failed', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Get services that are due for billing on given date
     *
     * Considers days_in_advance from article or global config
     */
    protected function getServicesDueForBilling(Carbon $date): Collection
    {
        $globalDays = config('larabill.recurring_billing.days_in_advance', 7);

        return ArticleServiceStatus::query()
            ->with(['article', 'customer', 'currentOverride'])
            ->active()
            ->where(function ($query) use ($date, $globalDays) {
                $query->where(function ($q) use ($date, $globalDays) {
                    // Services with next_billing_date within days_in_advance window
                    $q->whereNotNull('next_billing_date')
                        ->whereDate('next_billing_date', '<=', $date->copy()->addDays($globalDays));
                });
            })
            ->get()
            ->filter(fn ($service) => $this->shouldGenerateInvoice($service, $date));
    }

    /**
     * Check if invoice should be generated based on days_in_advance
     *
     * Uses article-specific days_in_advance if set, otherwise uses global config
     */
    protected function shouldGenerateInvoice(ArticleServiceStatus $service, Carbon $date): bool
    {
        $daysInAdvance = $service->article->billing_days_in_advance
            ?? config('larabill.recurring_billing.days_in_advance', 7);

        $generateDate = $service->next_billing_date->copy()->subDays($daysInAdvance);

        return $date->isSameDay($generateDate) || $date->isAfter($generateDate);
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
        $invoice = Invoice::create([
            'user_id'        => $customer->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'invoice_date'   => $date,
            'due_date'       => $date->copy()->addDays(config('larabill.recurring_billing.payment_terms_days', 15)),
            'status'         => InvoiceStatus::SENT,
            'total'          => $pricingDetails->appliedPrice,
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

        // Send notification if enabled
        if (config('larabill.recurring_billing.send_notifications', true)) {
            // TODO: Dispatch InvoiceCreated event/notification
            // event(new InvoiceCreated($invoice));
        }

        return $invoice;
    }

    /**
     * Calculate period end based on billing frequency
     *
     * Uses addMonths/addYears for accurate temporal calculations
     */
    protected function calculatePeriodEnd(ArticleServiceStatus $service): Carbon
    {
        $start = $service->next_billing_date->copy();

        return match ($service->article->billing_frequency) {
            BillingFrequency::MONTHLY   => $start->addMonths($service->article->billing_interval)->subDay(),
            BillingFrequency::QUARTERLY => $start->addMonths(3 * $service->article->billing_interval)->subDay(),
            BillingFrequency::YEARLY    => $start->addYears($service->article->billing_interval)->subDay(),
            default                     => $start->addMonth()->subDay(),
        };
    }

    /**
     * Calculate next billing date using addMonths/addYears
     *
     * This avoids discrepancies from day-based calculations
     */
    protected function calculateNextBillingDate(ArticleServiceStatus $service): Carbon
    {
        $current = $service->next_billing_date->copy();

        return match ($service->article->billing_frequency) {
            BillingFrequency::MONTHLY   => $current->addMonths($service->article->billing_interval),
            BillingFrequency::QUARTERLY => $current->addMonths(3 * $service->article->billing_interval),
            BillingFrequency::YEARLY    => $current->addYears($service->article->billing_interval),
            default                     => $current->addMonth(),
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
     * Uses existing InvoiceNumberingService if available
     */
    protected function generateInvoiceNumber(): string
    {
        // TODO: Use InvoiceNumberingService from main package
        return 'INV-'.now()->format('Y').'-'.str_pad((string) (Invoice::count() + 1), 6, '0', STR_PAD_LEFT);
    }
}
