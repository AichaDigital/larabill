<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Events\ServiceCancelled;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Support\DatabaseFailureSanitiser;
use AichaDigital\Larabill\ValueObjects\ScheduledClosureRunResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Service Lifecycle Management
 *
 * Handles activation, suspension, cancellation, and expiration of services
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class ServiceLifecycleService
{
    /**
     * Activate (or reinstate) a service — thin delegate to the ONE §3.2
     * activation path on the model (which dispatches `ServiceActivated`).
     */
    public function activate(ArticleServiceStatus $service): void
    {
        $service->activate();
    }

    /**
     * Suspend a service — thin delegate to the ONE §3.2 suspension path on
     * the model (which records the cause and dispatches `ServiceSuspended`).
     */
    public function suspend(ArticleServiceStatus $service, AgreementCause $cause, ?string $reason = null): void
    {
        $service->suspend($cause, $reason);
    }

    /**
     * Cancel a service — the DEPRECATED-SHIM forwarding route (ADR-015 §3.2,
     * per-path precision, gate round 8 P1 #1): forwards to the SAME close
     * path as the model shim with the seeded `legacy_cancellation` cause and
     * dispatches the legacy `@api` `ServiceCancelled` event so existing
     * listeners keep receiving it.
     *
     * @deprecated since 7.0.0, use `ServiceLifecycleService::suspend()` / the close API (`ArticleServiceStatus::close(cause)`) — see UPGRADE-7.0.md
     *
     * THIS path's legacy notice computation reads `metadata.notice_period_days`
     * VERBATIM — `ArticleServiceStatus::cancel()` reads a DIFFERENT metadata
     * route (`service.cancellation_policy.notice_days`); there is deliberately
     * no shared abstraction (gate round 8, P1 #1).
     */
    public function cancel(
        ArticleServiceStatus $service,
        CancellationType $type,
        ?string $reason = null
    ): void {
        $this->triggerLegacyDeprecation(
            'ServiceLifecycleService::cancel()',
            'ServiceLifecycleService::suspend() / the close API with the seeded `legacy_cancellation` cause',
        );

        $effectiveDate = $this->calculateCancellationEffectiveDate($service, $type);

        $service->close(
            AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation'),
            $reason,
            $effectiveDate->isFuture() ? $effectiveDate : null,
        );

        $metadata                 = $service->metadata ?? [];
        $metadata['cancellation'] = [
            'requested_at'   => now()->toIso8601String(),
            'effective_date' => $effectiveDate->toIso8601String(),
            'type'           => $type->value,
            'reason'         => $reason,
        ];

        // Legacy columns kept per §2.1 (untouched by the close API itself).
        $service->forceFill([
            'cancellation_requested_at' => now(),
            'cancellation_effective_at' => $effectiveDate,
            'refund_unused'             => $type->requiresRefund(),
        ])->save();

        $service->update(['metadata' => $metadata]);

        ServiceCancelled::dispatch($service->fresh() ?? $service, $type, $reason);
    }

    /**
     * Expire a service — thin delegate to the §4.2/§5 rewire on the model:
     * expiry CLOSES the agreement with the seeded `fulfilment` cause (the
     * retired EXPIRED state is gone) and dispatches `ServiceClosed`.
     */
    public function expire(ArticleServiceStatus $service): void
    {
        $service->expire();
    }

    /**
     * Calculate the value of the unused part of the current period.
     *
     * The base is what was ACTUALLY INVOICED for that period — the emitted
     * line, which carries its own price and service dates verbatim (AID-559)
     * — never the price the contract carries today (AID-956 D12). A contract
     * revision is a legitimate act of the consumer (D3) and only affects
     * periods not yet emitted (D4); measuring an already invoiced period
     * against a later revision would refund money the customer never paid, or
     * keep money that is theirs.
     *
     * When no emitted line covers the period — a service imported from a
     * previous system, a period larabill never invoiced — this falls back to
     * the contract price. It does not throw: this is an indicative figure the
     * consumer acts on, not a fiscal document.
     *
     * This returns an AMOUNT and nothing else. Whether anything is refunded at
     * all, under which commercial policy, and which fiscal artefact documents
     * it, is not larabill's to decide — see docs/ADR-014.
     *
     * @return int|null Amount in Base100, or null if no refund
     */
    public function calculateRefund(ArticleServiceStatus $service): ?int
    {
        if (! $service->refund_unused || ! $service->next_billing_date || $service->effective_price === null) {
            return null;
        }

        $today              = now()->startOfDay();
        $nextBillingDate    = $service->next_billing_date->startOfDay();
        $lastBillingDate    = $this->calculateLastBillingDate($service);
        $totalDaysInPeriod  = $lastBillingDate->diffInDays($nextBillingDate);
        $unusedDays         = $today->diffInDays($nextBillingDate);

        if ($unusedDays <= 0 || $totalDaysInPeriod <= 0) {
            return null;
        }

        // Proportional refund, settled to the cent with HalfUp (EU/Spain norm).
        $refundAmount = ($this->invoicedAmountForCurrentPeriod($service) ?? $service->effective_price)
            ->multipliedBy((int) $unusedDays)
            ->dividedBy((int) $totalDaysInPeriod, 2, RoundingMode::HalfUp)
            ->unscaledValue();

        return $refundAmount;
    }

    /**
     * Process all services that have expired — delegates to the ONE locked
     * materialiser (`processScheduledClosures()`), which handles BOTH
     * `close_scheduled_at` and `expires_at` in the same pass (gate round 8,
     * P2 #2). Expiry closes the agreement with the seeded `fulfilment` cause
     * (§4.2 row 4); the retired EXPIRED state is gone.
     *
     * @return int Number of agreements materialised (closed) this run — the
     *             truthful count (AID-1590), excluding rows skipped as already
     *             materialised or deleted.
     */
    public function processExpiredServices(): int
    {
        return $this->processScheduledClosures()->materialised;
    }

    /**
     * Process all pending cancellations that should take effect — SUBSUMED
     * by `processScheduledClosures()` (ADR-015 §5): the deferred-closure
     * carry-over lives in `close_scheduled_at` (materialised by the single
     * locked materialiser, which also re-dispatches the legacy
     * `ServiceCancelled` event for cancel()-shim rows). Kept as a delegating
     * entry point until the T7 shim slice annotates it; the old
     * `cancellation_effective_at`-driven pass is gone.
     *
     * @return int Number of agreements materialised (closed) this run — the
     *             truthful count (AID-1590), excluding rows skipped as already
     *             materialised or deleted.
     *
     * @deprecated since 7.0.0, use `ServiceLifecycleService::processScheduledClosures()` (the scheduler subsumes deferred closures and expiry) — see UPGRADE-7.0.md
     */
    public function processPendingCancellations(): int
    {
        return $this->processScheduledClosures()->materialised;
    }

    /**
     * Process all deferred closures and expiries that have come due — the
     * ONE locked materialiser of §3.2 (gate round 8, P2 #2): it handles BOTH
     * `close_scheduled_at` and `expires_at` in the same pass, so scheduler
     * order never determines the resulting history. Precedence when both
     * fields exist: whichever comes FIRST wins and the other is ignored at
     * closure time; on EQUAL dates the scheduled close wins — an explicit
     * close decision outranks the automatic expiry.
     *
     * A scheduled closure materialises with its STORED cause (nullable —
     * migrated 6.x rows carry cause NULL); when the row carries a legacy
     * `cancellation.type` in metadata (the cancel() shim route, including
     * rows migrated from 6.x `cancel()`), the legacy `@api`
     * `ServiceCancelled` event is re-dispatched on materialisation so
     * existing listeners keep receiving their cancellations (§5, gate round
     * 10 P1).
     *
     * Uses one transaction per service with `FOR UPDATE` revalidation for
     * idempotency.
     *
     * The counter counts ONLY materialisations: a row found no longer
     * `ACTIVE` under `lockForUpdate()` (another process closed or suspended
     * it, or deleted it) is skipped and not counted (AID-1590). Per-row
     * failures are logged SANITISED — `DatabaseFailureSanitiser::message()`,
     * never raw SQL or bindings (AID-1442 pattern) — and reported through
     * the result's `failedServiceIds`, so the caller can distinguish
     * "nothing to close" from "everything failed".
     *
     * @return ScheduledClosureRunResult The run's outcome: how many closures
     *                                   were actually materialised this run, and which agreements
     *                                   failed (left open for the next run).
     */
    public function processScheduledClosures(): ScheduledClosureRunResult
    {
        $today = Carbon::today()->toDateString();

        $due = ArticleServiceStatus::query()
            ->where('status', AgreementStatus::ACTIVE)
            ->where(function ($query) use ($today): void {
                $query->where(function ($q) use ($today): void {
                    $q->whereNotNull('close_scheduled_at')
                        ->where('close_scheduled_at', '<=', $today);
                })->orWhere(function ($q) use ($today): void {
                    $q->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $today);
                });
            })
            ->get();

        $materialised     = 0;
        $failedServiceIds = [];

        foreach ($due as $service) {
            try {
                $closed = DB::transaction(function () use ($service): bool {
                    /** @var ArticleServiceStatus $fresh */
                    $fresh = ArticleServiceStatus::query()
                        ->whereKey($service->getKey())
                        ->lockForUpdate()
                        ->first();

                    // Skip if already materialised (idempotency): another
                    // process closed/suspended it, or deleted it.
                    if ($fresh === null || $fresh->status !== AgreementStatus::ACTIVE) {
                        return false;
                    }

                    $scheduled = $fresh->close_scheduled_at?->startOfDay();
                    $expires   = $fresh->expires_at?->startOfDay();

                    if ($scheduled !== null && ($expires === null || $scheduled->lte($expires))) {
                        $this->materialiseScheduledClose($fresh);
                    } else {
                        // Expiry wins (or no schedule at all): closes with
                        // the seeded `fulfilment` cause (§4.2 row 4).
                        $fresh->expire();
                    }

                    return true;
                });

                // Count only actual materialisations (AID-1590): a skipped
                // row returns false above and never reaches this line.
                if ($closed) {
                    $materialised++;
                }
            } catch (\Throwable $e) {
                $failedServiceIds[] = $service->id;

                Log::error('Failed to materialise agreement closure', [
                    'service_id' => $service->id,
                    'error'      => DatabaseFailureSanitiser::message($e),
                ]);
            }
        }

        return new ScheduledClosureRunResult($materialised, $failedServiceIds);
    }

    /**
     * Materialise one scheduled close under the caller's transaction: close
     * with the STORED cause (nullable — legal) and re-dispatch the legacy
     * `ServiceCancelled` event when the row carries the cancel() shim's type
     * marker.
     *
     * An UNRESOLVABLE stored cause (AID-1594 — retired with `is_active =
     * false`, or missing, between scheduling and materialisation) never
     * fails the materialisation: closed + NULL cause is a legal state (§2.2
     * cause-optional), and leaving the row open forever re-failing every run
     * would be the worse outcome. The original cause key and the situation
     * travel in the closure metadata (`materialised_cause_key` /
     * `materialised_cause_situation`); genuinely unexpected failures keep
     * going through `failedServiceIds` (AID-1590).
     */
    private function materialiseScheduledClose(ArticleServiceStatus $service): void
    {
        $legacyType = $service->metadata['cancellation']['type'] ?? null;

        $cause     = $service->closureCause;
        $situation = null;

        if ($cause !== null) {
            try {
                $cause = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, $cause->key);
            } catch (InvalidArgumentException) {
                $situation = $service->closureCause->is_active ? 'wrong_scope' : 'retired';
                $cause     = null;
            }
        } elseif ($service->closure_cause_id !== null) {
            // FK guarantees existence in practice; defensive only.
            $situation = 'missing';
        }

        if ($situation !== null) {
            $service->setMetadata('closure', array_merge($service->metadata['closure'] ?? [], [
                'materialised_cause_key'       => $service->closureCause?->key,
                'materialised_cause_situation' => $situation,
            ]));
        }

        $service->close(
            $cause,
            $service->metadata['closure']['note'] ?? null,
        );

        // The type marker survives a JSON round-trip as an int (the enum's
        // backed value); accept both representations.
        if ($legacyType !== null && (is_int($legacyType) || (is_string($legacyType) && $legacyType !== ''))) {
            ServiceCancelled::dispatch(
                $service->fresh() ?? $service,
                CancellationType::from((int) $legacyType),
                $service->metadata['cancellation']['reason'] ?? null,
            );
        }
    }

    /**
     * Calculate the effective date for cancellation based on type
     *
     * @deprecated since 7.0.0, schedule the close with its effective date (`ArticleServiceStatus::close(cause, note, effectiveAt)`) — see UPGRADE-7.0.md
     */
    protected function calculateCancellationEffectiveDate(
        ArticleServiceStatus $service,
        CancellationType $type
    ): Carbon {
        return match ($type) {
            CancellationType::IMMEDIATE     => now(),
            CancellationType::END_OF_PERIOD => $service->next_billing_date ?? now(),
            CancellationType::NOTICE_PERIOD => now()->addDays(
                $service->article->metadata['notice_period_days'] ?? 30
            ),
        };
    }

    /**
     * The amount actually invoiced for the period the service is in today.
     *
     * Located through the service reference the recurring engine freezes on
     * every line it emits (`metadata.source_reference.service_status_id`),
     * which is what distinguishes two agreements of the same customer for the
     * same article and frequency — something ArticleOverride cannot express.
     */
    protected function invoicedAmountForCurrentPeriod(ArticleServiceStatus $service): ?FixedDecimal
    {
        $today = now()->toDateString();

        $line = InvoiceItem::query()
            // @phpstan-ignore argument.type (Eloquent JSON path operator, not a model column)
            ->where('metadata->source_reference->service_status_id', $service->getKey())
            ->whereDate('service_date_from', '<=', $today)
            ->whereDate('service_date_to', '>=', $today)
            ->orderByDesc('id')
            ->first();

        return $line?->taxable_amount;
    }

    /**
     * Calculate last billing date (approximation based on the agreement's
     * cadence)
     *
     * Resolves the ADR-015 interval (unit + quantity) and delegates to
     * `BillingInterval::subtractFromDate()` — whose NoOverflow semantics are
     * PINNED to the old `BillingFrequency::subtractFromDate()` math. A NULL
     * cadence (one-time agreement) keeps the legacy ONE_TIME behaviour: the
     * date comes back unchanged.
     */
    protected function calculateLastBillingDate(ArticleServiceStatus $service): Carbon
    {
        if (! $service->next_billing_date) {
            return now();
        }

        // The agreement's cadence (stored at contract time)
        return $service->billingInterval()?->subtractFromDate($service->next_billing_date->copy())
            ?? $service->next_billing_date->copy();
    }

    /**
     * ONE legacy-deprecation emitter for this class's shim paths (ADR-015
     * §5): every delegating public path emits the same-shaped
     * `E_USER_DEPRECATED` through here. `processPendingCancellations()` is
     * annotated but does NOT emit — the package's own `larabill:process-cancellations`
     * action still calls it internally, and internal code must not emit.
     */
    private function triggerLegacyDeprecation(string $method, string $replacement): void
    {
        trigger_error(sprintf(
            '%s is deprecated since larabill 7.0.0, use %s instead — see UPGRADE-7.0.md.',
            $method,
            $replacement,
        ), E_USER_DEPRECATED);
    }
}
