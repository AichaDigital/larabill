<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Contracts\Services\RecurringEmissionHookContract;
use AichaDigital\Larabill\DataTransferObjects\BillingDetails;
use AichaDigital\Larabill\DataTransferObjects\InvoiceItemMetadata;
use AichaDigital\Larabill\DataTransferObjects\SourceReference;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Events\RecurringBillingCompleted;
use AichaDigital\Larabill\Events\RecurringBillingFailed;
use AichaDigital\Larabill\Events\RecurringInvoiceGenerated;
use AichaDigital\Larabill\Exceptions\MissingRecurringEmissionHookException;
use AichaDigital\Larabill\Exceptions\MissingUserTaxProfileException;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\UserTaxProfile;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recurring Billing Service
 *
 * Handles all recurring billing logic including:
 * - Finding services due for billing
 * - Emitting through the canonical path (InvoiceService::createInvoice) so
 *   every recurring invoice is born with receiver, snapshots, taxes and
 *   seal (AID-836; design spec
 *   docs/superpowers/specs/2026-08-06-aid-836-recurring-emission-contract.md)
 * - Respecting days_in_advance configuration
 * - An atomic per-service emission boundary: creation, numbering, sealing,
 *   next_billing_date advance and the consumer emission hook commit or roll
 *   back together
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class RecurringBillingService
{
    /**
     * Deprecated as of AID-836 (prose, not tag: the constructor still
     * assigns it for BC) — numbering is owned by the canonical emission
     * path (InvoiceService → InvoiceNumberingService); kept only for
     * constructor signature compatibility. Removal in the next major.
     */
    protected InvoiceNumberingService $invoiceNumbering;

    /**
     * Deprecated as of AID-836 (prose, not tag: the constructor still
     * assigns it for BC) — series resolution is owned by the canonical
     * emission path (InvoiceService → InvoiceSeriesResolver); kept only for
     * constructor signature compatibility. Removal in the next major.
     */
    protected InvoiceSeriesResolver $seriesResolver;

    protected InvoiceService $invoiceService;

    protected ?RecurringEmissionHookContract $emissionHook;

    public function __construct(
        protected PricingService $pricingService,
        ?InvoiceNumberingService $invoiceNumbering = null,
        ?InvoiceSeriesResolver $seriesResolver = null,
        ?InvoiceService $invoiceService = null,
        ?RecurringEmissionHookContract $emissionHook = null
    ) {
        $this->invoiceNumbering = $invoiceNumbering ?? app(InvoiceNumberingService::class);
        $this->seriesResolver   = $seriesResolver   ?? app(InvoiceSeriesResolver::class);
        $this->invoiceService   = $invoiceService   ?? app(InvoiceService::class);
        $this->emissionHook     = $emissionHook;
    }

    /**
     * Process recurring billing for services due on given date
     *
     * $date decides ELIGIBILITY only (which services are due). The invoice
     * itself carries the real emission instant — invoice_date, issued_at,
     * numbering fiscal year and snapshots are all anchored to now() by the
     * canonical path (spec D8). The billed period lives in the line's
     * service_date_from/to.
     *
     * @param  Carbon  $date  Processing date (eligibility)
     * @param  bool  $dryRun  If true, simulates without creating invoices
     * @return array{processed: int, skipped: int, failed: int, invoices: list<int|string>, errors: list<array<string, mixed>>}
     */
    public function processRecurringBilling(Carbon $date, bool $dryRun = false): array
    {
        $emissionHook = $this->resolveEmissionHook();

        // Opt-in gate (spec D4): fail BEFORE issuing anything when the
        // consumer declared the in-boundary hook mandatory and none is
        // bound. Dry runs are exempt — they emit nothing and must stay
        // usable to diagnose a not-yet-configured installation.
        if (! $dryRun
            && $emissionHook === null
            && (bool) config('larabill.recurring_billing.require_emission_hook', false)) {
            throw MissingRecurringEmissionHookException::create();
        }

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
                    $emission = $this->emitWithinBoundary($service, $date, $emissionHook);

                    if ($emission['invoice'] === null) {
                        // Another run processed this period, or the service
                        // stopped being billable while we waited for the
                        // lock — nothing to do here (spec D5 b/b').
                        $results['skipped']++;

                        continue;
                    }

                    $results['invoices'][] = $emission['invoice']->id;

                    if ($emission['created']) {
                        // Best-effort, at-most-once notification (spec D9):
                        // dispatched post-commit with the locked/updated
                        // service instance; a throwing listener must not
                        // reclassify an already-emitted invoice as failed.
                        $this->dispatchBestEffort(
                            fn () => RecurringInvoiceGenerated::dispatch($emission['invoice'], $emission['service'])
                        );
                    }
                }

                $results['processed']++;

            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'service_id'  => $service->id,
                    'customer_id' => $service->customer_id,
                    'article_id'  => $service->article_id,
                    'error'       => $e->getMessage(),
                ];

                $this->dispatchBestEffort(
                    fn () => RecurringBillingFailed::dispatch($service, $e, [
                        'billing_date' => $date->toDateString(),
                    ])
                );

                // Log error for monitoring
                Log::error('Recurring billing failed', [
                    'service_id' => $service->id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
            }
        }

        $this->dispatchBestEffort(
            fn () => RecurringBillingCompleted::dispatch($date, $results, $dryRun)
        );

        return $results;
    }

    /**
     * Run one service through the atomic emission boundary (spec D5).
     *
     * attempts: 3 (AID-570) — the canonical createInvoice() transaction and
     * the numbering first-use protection both run NESTED here (savepoints),
     * so a deadlock aborts THIS transaction and only a retry at this
     * outermost boundary can recover. CONTRACT: the closure stays
     * side-effect-free outside the database — a retry re-runs it in full
     * (including the consumer hook, whose contract says DB-only).
     *
     * @return array{invoice: ?Invoice, created: bool, service: ArticleServiceStatus}
     */
    protected function emitWithinBoundary(
        ArticleServiceStatus $service,
        Carbon $date,
        ?RecurringEmissionHookContract $emissionHook
    ): array {
        // The period THIS run selected, captured before the lock. If another
        // overlapping run already billed it, the re-read below shows an
        // advanced date and this run must stand down instead of emitting the
        // NEXT period early (spec D5.b).
        $expectedPeriodStart = $service->next_billing_date;

        return DB::transaction(function () use ($service, $expectedPeriodStart, $date, $emissionHook): array {
            // Re-read under lock, revalidating the SAME selection criteria
            // as getServicesInBillingWindow() (active + billable): a
            // concurrent suspension keeps next_billing_date, so the period
            // check alone would not catch it (spec D5.b'). The lock also
            // makes retries resume-safe: a rolled-back attempt leaves the
            // in-memory model with an advanced date, the fresh re-read does
            // not.
            $svc = ArticleServiceStatus::query()
                ->whereKey($service->id)
                ->active()
                ->whereNotNull('next_billing_date')
                ->lockForUpdate()
                ->first();

            if ($svc                    === null
                || $expectedPeriodStart === null
                || ! $svc->next_billing_date?->isSameDay($expectedPeriodStart)) {
                return ['invoice' => null, 'created' => false, 'service' => $service];
            }

            $emission = $this->createInvoiceForService($svc, $date);

            // Advance runs on the created AND the idempotent path: the
            // latter is the repair route after a crash between the invoice
            // commit and the advance (spec D6).
            $this->updateNextBillingDate($svc);

            if ($emission['created'] && $emissionHook !== null) {
                $emissionHook->afterEmission($emission['invoice'], $svc);
            }

            return $emission + ['service' => $svc];
        }, 3);
    }

    /**
     * Resolve the emission hook: an injected instance wins, then the
     * consumer's container binding, resolved at call time so a binding
     * registered after construction is still honoured.
     */
    protected function resolveEmissionHook(): ?RecurringEmissionHookContract
    {
        if ($this->emissionHook !== null) {
            return $this->emissionHook;
        }

        return app()->bound(RecurringEmissionHookContract::class)
            ? app(RecurringEmissionHookContract::class)
            : null;
    }

    /**
     * Dispatch a recurring-billing event as best-effort notification (spec
     * D9): listeners are outside the fiscal result — a throwing listener is
     * logged and never alters counters nor aborts the run. Durable delivery
     * needs an outbox, which is out of this line's scope.
     *
     * @param  callable(): void  $dispatch
     */
    protected function dispatchBestEffort(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            Log::error('Recurring billing event listener failed', [
                'error' => $e->getMessage(),
            ]);
        }
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
     * Emit the invoice for a recurring service through the canonical path.
     *
     * Runs INSIDE the emitWithinBoundary() transaction — it opens no
     * transaction of its own (spec D5). Delegates to
     * InvoiceService::createInvoice() so the invoice is born with receiver
     * (billable_user_id), issuer, fiscal config, series, numbering, taxes,
     * the three encrypted snapshots and the seal (make_immutable), exactly
     * like every other emission path (spec D1/D2).
     *
     * Includes an idempotency check bounded to REAL recurring emissions
     * (source_reference.type + non-proforma invoice, spec D6) so a crash
     * between the invoice commit and the next_billing_date advance never
     * duplicates a period.
     *
     * @return array{invoice: Invoice, created: bool}
     */
    protected function createInvoiceForService(ArticleServiceStatus $service, Carbon $date): array
    {
        $periodStart = $service->next_billing_date;
        if ($periodStart === null) {
            throw new \RuntimeException("Cannot bill service {$service->id} without a next_billing_date.");
        }

        $customer = $service->customer;
        if ($customer === null) {
            throw new \RuntimeException("Cannot bill service {$service->id} without a customer.");
        }

        // Unattended emission must not degrade silently to a fiscally
        // unidentified (F2) invoice: the receiver needs a valid profile AND
        // a non-empty tax_id at emission time (spec D7). Checked here,
        // inside the boundary, so a violation rolls back everything.
        $taxProfile = UserTaxProfile::getValidForOwnerAt((string) $customer->getKey(), now());

        if ($taxProfile === null) {
            throw MissingUserTaxProfileException::missingProfile($service->id, (string) $customer->getKey());
        }

        if (blank($taxProfile->tax_id)) {
            throw MissingUserTaxProfileException::missingTaxId($service->id, (string) $customer->getKey());
        }

        // Idempotency check: an existing line for this service + period
        // counts only when it is a REAL recurring emission — scoped by
        // source type and excluding proformas, which may legitimately carry
        // the same metadata through the canonical API (spec D6).
        $existingInvoice = InvoiceItem::query()
            ->whereRaw(
                "json_extract(metadata, '$.source_reference.service_status_id') = ?",
                [$service->id]
            )
            ->whereRaw(
                "json_extract(metadata, '$.source_reference.type') = ?",
                ['article_service']
            )
            ->whereDate('service_date_from', $periodStart->toDateString())
            ->with('invoice')
            ->get()
            ->map(fn (InvoiceItem $item) => $item->invoice)
            ->first(fn ($candidate) => $candidate instanceof Invoice
                && $candidate->serie !== InvoiceSerieType::PROFORMA);

        if ($existingInvoice instanceof Invoice) {
            // The invoice for this period already exists (repair path). The
            // hook and the event must NOT run again for it — they committed
            // together with the invoice when it was created. Legacy
            // invoices predating AID-836 are also returned here as-is;
            // sealing or registering them retroactively is consumer
            // backfill, not this flow's job.
            return ['invoice' => $existingInvoice, 'created' => false];
        }

        $article = $service->article;

        // Calculate billing period
        $periodEnd = $this->calculatePeriodEnd($service);

        // The amount comes from the CONTRACT, never re-derived from the
        // catalogue or overrides at emission time (ADR-004, AID-956 D1).
        $pricingDetails = $this->pricingService->createPricingDetailsForContract($service);

        // Calculate next billing date (for metadata)
        $nextBillingDate = $this->calculateNextBillingDate($service);

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

        $invoice = $this->invoiceService->createInvoice([
            'billable_user_id' => (string) $customer->getKey(),
            'status'           => InvoiceStatus::SENT->value,
            'due_date'         => now()->addDays((int) config('larabill.recurring_billing.payment_terms_days', 15))->toDateString(),
            'items'            => [
                [
                    'article_id'        => $article->id,
                    'item_type'         => $article->item_type,
                    'description'       => (string) $article->getTranslation('name', app()->getLocale()),
                    'quantity'          => 100, // base-100: 1.00 unit
                    'base_price'        => (int) $pricingDetails->appliedPrice,
                    'service_date_from' => $periodStart->toDateString(),
                    'service_date_to'   => $periodEnd->toDateString(),
                    'metadata'          => $metadata->toArray(),
                ],
            ],
        ], ['make_immutable' => true]);

        return ['invoice' => $invoice, 'created' => true];
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
}
