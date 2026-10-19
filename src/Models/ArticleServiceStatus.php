<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Database\Factories\ArticleServiceStatusFactory;
use AichaDigital\Larabill\Enums\AgreementModality;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Events\ServiceActivated;
use AichaDigital\Larabill\Events\ServiceCancelled;
use AichaDigital\Larabill\Events\ServiceClosed;
use AichaDigital\Larabill\Events\ServiceReinstated;
use AichaDigital\Larabill\Events\ServiceSuspended;
use AichaDigital\Larabill\Exceptions\IllegalAgreementTransitionException;
use AichaDigital\Larabill\Exceptions\MissingContractPriceException;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Article Service Status Model
 *
 * @property int $id
 * @property int|string $customer_id
 * @property int $article_id
 * @property string|null $instance_identifier
 * @property string|null $instance_name
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $next_billing_date
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property AgreementStatus $status
 * @property \Illuminate\Support\Carbon|null $cancellation_requested_at
 * @property \Illuminate\Support\Carbon|null $cancellation_effective_at
 * @property bool $refund_unused
 * @property int|null $closure_cause_id Cause vocabulary FK (agreement_causes); only meaningful for closed/suspended, NULL is legal (ADR-015 §2.2)
 * @property \Illuminate\Support\Carbon|null $close_scheduled_at Deferred closure date (ADR-015 §2.1/§3.2): the scheduler closes the agreement on this date
 * @property string $account_status Accounting axis, independent of service state (ADR-015 §2.1): 'up_to_date' | 'overdue'
 * @property string $renewal Renewal attribute (ADR-015 §2.1): 'auto' | 'closed_term'
 * @property AgreementModality $modality Contract modality (ADR-015 §2.1): recurring | one_time | non_billable
 * @property string|null $billing_unit 'month'|'year'; NULL = one-time (ADR-015)
 * @property int|null $billing_quantity 1-12 (months) / 1-10 (years); NULL = one-time
 * @property int|null $guarantee_days Guarantee window snapshot frozen at contracting (ADR-015 §3.4); NULL/0 = no window
 * @property FixedDecimal|null $effective_price
 * @property int|null $current_override_id
 * @property string|null $external_reference
 * @property string|null $origin_key Import idempotency key (ADR-015 §2.5): format `whmcs:<source_table>:<source_id>` — the importer's convention, not enforced here; UNIQUE so a re-import UPDATES instead of duplicating
 * @property array<string, mixed>|null $instance_data
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Article $article
 * @property-read ArticleOverride|null $currentOverride
 * @property-read AgreementCause|null $closureCause
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class ArticleServiceStatus extends Model
{
    /** @use HasFactory<ArticleServiceStatusFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'article_service_status';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'article_id',
        'instance_identifier',
        'instance_name',
        'started_at',
        'next_billing_date',
        'expires_at',
        'status',
        'cancellation_requested_at',
        'cancellation_effective_at',
        'refund_unused',
        'closure_cause_id',
        'close_scheduled_at',
        'account_status',
        'renewal',
        'modality',
        'billing_unit',
        'billing_quantity',
        'guarantee_days',
        'effective_price',
        'current_override_id',
        'external_reference',
        'origin_key',
        'instance_data',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status'                    => AgreementStatus::class,
        'modality'                  => AgreementModality::class,
        'started_at'                => 'date',
        'next_billing_date'         => 'date',
        'expires_at'                => 'date',
        'cancellation_requested_at' => 'date',
        'cancellation_effective_at' => 'date',
        'close_scheduled_at'        => 'date',
        'refund_unused'             => 'boolean',
        'closure_cause_id'          => 'integer',
        'billing_quantity'          => 'integer',
        'guarantee_days'            => 'integer',
        'effective_price'           => AichaMoneyCast::class.':2',
        'instance_data'             => 'array',
        'metadata'                  => 'array',
    ];

    /**
     * Get the article for this service status.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the customer for this service.
     * Note: Relationship is agnostic, uses configured user model.
     *
     * @return BelongsTo<Model, $this>
     */
    public function customer(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'customer_id');
    }

    /**
     * Get the seeded cause that closed or suspended this agreement (ADR-015
     * §2.2). NULL is a legal state — cause is optional on closed agreements —
     * and only meaningful while `status` is closed/suspended. The closed vs
     * suspended scope of the referenced cause is application-enforced
     * (`AgreementCause::findActive()`); the FK proves existence only.
     *
     * @return BelongsTo<AgreementCause, $this>
     */
    public function closureCause(): BelongsTo
    {
        return $this->belongsTo(AgreementCause::class, 'closure_cause_id');
    }

    /**
     * Get the current active price override.
     *
     * @return BelongsTo<ArticleOverride, $this>
     */
    public function currentOverride(): BelongsTo
    {
        return $this->belongsTo(ArticleOverride::class, 'current_override_id');
    }

    /**
     * Scope to filter only active services.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AgreementStatus::ACTIVE);
    }

    /**
     * Scope to filter services pending billing.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopePendingBilling(Builder $query): void
    {
        $query->where('status', AgreementStatus::ACTIVE)
            ->whereNotNull('next_billing_date');
    }

    /**
     * Scope to filter services due for billing.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeDueForBilling(Builder $query, ?Carbon $date = null): void
    {
        $date = $date ?? now();

        $query->where('status', AgreementStatus::ACTIVE)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $date);
    }

    /**
     * Scope to filter by customer.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeForCustomer(Builder $query, int|string $customerId): void
    {
        $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by article.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeForArticle(Builder $query, int $articleId): void
    {
        $query->where('article_id', $articleId);
    }

    /**
     * Scope to filter by the import idempotency key (ADR-015 §2.5): the
     * importer matches an already-imported record by `origin_key` and
     * UPDATES it instead of duplicating. Never matches NULL keys — a native
     * (never-imported) agreement is not a global candidate.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeByOriginKey(Builder $query, ?string $key): void
    {
        if ($key === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('origin_key', $key);
    }

    /**
     * Find the agreement previously imported under the given origin key
     * (ADR-015 §2.5) — the lookup half of the importer's find-or-UPDATE
     * idempotency contract; the update flow itself belongs to the importer.
     * Returns null for a NULL key (no accidental global match over the
     * never-imported population) and for an unmatched key.
     */
    public static function findByOriginKey(?string $key): ?self
    {
        if ($key === null) {
            return null;
        }

        return static::query()->byOriginKey($key)->first();
    }

    /**
     * Scope to filter by status.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeWithStatus(Builder $query, AgreementStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Activate (or reinstate) the service through the §3.1 state machine:
     * `pending → active` (provisioning) and `suspended → active`
     * (reinstatement) are the only legal edges. Reinstatement clears the
     * suspension cause — the narrative stays in the suspension metadata.
     *
     * @throws IllegalAgreementTransitionException on every other edge
     */
    public function activate(): void
    {
        // Captured BEFORE the transition: the reinstatement edge is
        // suspended → active (ADR-015 §3.2); provisioning (pending → active)
        // dispatches only ServiceActivated.
        $wasSuspended = $this->status === AgreementStatus::SUSPENDED;

        $fresh = $this->applyTransition(AgreementStatus::ACTIVE, function (ArticleServiceStatus $fresh): void {
            $updates = [
                'status'     => AgreementStatus::ACTIVE,
                'started_at' => $fresh->started_at ?? now(),
            ];

            if ($fresh->status === AgreementStatus::SUSPENDED) {
                $updates['closure_cause_id'] = null;
            }

            $fresh->update($updates);
        });

        ServiceActivated::dispatch($fresh);

        // ADR-015 §3.2: the reinstatement edge dispatches the dedicated
        // ServiceReinstated — IN ADDITION to ServiceActivated, whose existing
        // listeners keep working.
        if ($wasSuspended) {
            ServiceReinstated::dispatch($fresh);
        }
    }

    /**
     * Suspend the service through the §3.2 API — the ONE suspension path
     * (ADR-014 Enmienda 1: no duplicated implementations, no invented
     * defaults). The cause must be an ACTIVE `suspended`-scope cause,
     * resolved through `AgreementCause::findActive()` — a closure cause
     * cannot suspend an agreement.
     *
     * @throws IllegalAgreementTransitionException on a non-suspendable state
     * @throws InvalidArgumentException on wrong scope / inactive / unknown cause
     */
    public function suspend(AgreementCause $cause, ?string $note = null): void
    {
        AgreementCause::findActive(AgreementCause::SCOPE_SUSPENDED, $cause->key);

        $fresh = $this->applyTransition(AgreementStatus::SUSPENDED, function (ArticleServiceStatus $fresh) use ($cause, $note): void {
            $metadata               = $fresh->metadata ?? [];
            $metadata['suspension'] = [
                'suspended_at' => now()->toIso8601String(),
                'reason'       => $note,
                'cause_key'    => $cause->key,
            ];

            $fresh->update([
                'status'           => AgreementStatus::SUSPENDED,
                'closure_cause_id' => $cause->id,
                'metadata'         => $metadata,
            ]);
        });

        ServiceSuspended::dispatch($fresh, $note, $cause);
    }

    /**
     * Close the agreement through the §3.2 API — the ONE closure path (the
     * subsume of both legacy `cancel()` implementations, ADR-014 Enmienda 1).
     *
     * The cause must be an ACTIVE `closed`-scope cause resolved through
     * `AgreementCause::findActive()` — a suspension cause cannot close an
     * agreement. Cause is OPTIONAL: `closed` + NULL cause is a legal state
     * (the import requirement, §2.2); the narrative lives in the consumer's
     * order notes, never invented by the package.
     *
     * A FUTURE `$effectiveAt` schedules the close (§3.2): the agreement
     * stays `active` with `close_scheduled_at` = `$effectiveAt` and the
     * stored cause, and the scheduler materialises it on that date (with
     * this same cause, dispatching `ServiceClosed` there too). The emission
     * gate never bills a period reaching beyond the scheduled date.
     *
     * A scheduled close REQUIRES the agreement to be ACTIVE before the
     * transition (AID-1593): the materialiser only processes ACTIVE rows,
     * so a schedule planted from `suspended`/`pending`/`guarantee` would
     * never fire. The guard is judged on the FRESH state under the
     * `applyTransition` lock and throws typed loud; the immediate close
     * (effectiveAt null/past) keeps working from every state the §3.1
     * transition map allows.
     *
     * @throws IllegalAgreementTransitionException on a non-closable state, or when scheduling from a non-active state (AID-1593)
     * @throws InvalidArgumentException on wrong scope / inactive / unknown cause
     */
    public function close(?AgreementCause $cause = null, ?string $note = null, ?Carbon $effectiveAt = null): void
    {
        if ($cause !== null) {
            AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, $cause->key);
        }

        $scheduled = $effectiveAt !== null && $effectiveAt->startOfDay()->isFuture();

        $fresh = $this->applyTransition(AgreementStatus::CLOSED, function (ArticleServiceStatus $fresh) use ($cause, $note, $effectiveAt, $scheduled): void {
            if ($scheduled && $fresh->status !== AgreementStatus::ACTIVE) {
                throw IllegalAgreementTransitionException::scheduledCloseFromNonActive($fresh->status);
            }

            $metadata             = $fresh->metadata ?? [];
            $metadata['closure']  = array_merge($metadata['closure'] ?? [], [
                'cause_key'   => $cause?->key,
                'note'        => $note,
                'scheduled'   => $scheduled,
                'recorded_at' => now()->toIso8601String(),
            ]);

            $updates = [
                'closure_cause_id' => $cause?->id,
                'metadata'         => $metadata,
            ];

            if ($scheduled) {
                $updates['close_scheduled_at'] = $effectiveAt->toDateString();
            } else {
                $updates['status']             = AgreementStatus::CLOSED;
                $updates['next_billing_date']  = null;
                $updates['close_scheduled_at'] = null;
            }

            $fresh->update($updates);
        });

        if (! $scheduled) {
            ServiceClosed::dispatch($fresh, $cause, $note);
        }
    }

    /**
     * Cancel a scheduled close (§3.2, gate round 8 P2 #3): the agreement
     * stays `active` with NO close scheduled, the scheduled cause is
     * cleared, and the cancellation of the schedule is recorded in the
     * closure metadata. This is the route an agreement must take before a
     * plan change (§3.5).
     *
     * @throws IllegalAgreementTransitionException unless the agreement is active
     */
    public function cancelScheduledClose(?string $note = null): void
    {
        // Not a state-machine transition (the agreement stays active) — a
        // guarded clearing of the schedule under the same lock/revalidation
        // discipline as every other transition.
        DB::transaction(function () use ($note): void {
            /** @var ArticleServiceStatus $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== AgreementStatus::ACTIVE) {
                throw IllegalAgreementTransitionException::requiresActive($fresh->status);
            }

            $metadata            = $fresh->metadata ?? [];
            $metadata['closure'] = array_merge($metadata['closure'] ?? [], [
                'schedule_cancelled_at'   => now()->toIso8601String(),
                'schedule_cancelled_note' => $note,
            ]);

            $fresh->update([
                'close_scheduled_at' => null,
                'closure_cause_id'   => null,
                'metadata'           => $metadata,
            ]);
        });

        $this->refresh();
    }

    /**
     * Expire the service — the §4.2/§5 rewire: expiry now CLOSES the
     * agreement with the seeded `fulfilment` cause (the only mapping the
     * source data establishes) instead of leaving it in the retired
     * EXPIRED state. Dispatches `ServiceClosed` (expiry IS a closure under
     * ADR-015); the legacy `ServiceExpired` event is no longer dispatched
     * from this path.
     *
     * @throws IllegalAgreementTransitionException on a non-closable state
     * @throws InvalidArgumentException if the `fulfilment` seed is missing
     */
    public function expire(): void
    {
        if ($this->expires_at === null) {
            $this->update(['expires_at' => now()]);
        }

        $this->close(AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'fulfilment'));
    }

    /**
     * Cancel the service — the DEPRECATED-SHIM forwarding route (ADR-015 §3.2
     * shim timing, per-path precision, gate round 8 P1 #1).
     *
     * @deprecated since 7.0.0, use `ArticleServiceStatus::close(cause)` / `ArticleServiceStatus::suspend(cause)` (the shim forwards with the seeded `legacy_cancellation` cause and keeps dispatching the legacy `ServiceCancelled` event) — see UPGRADE-7.0.md
     *
     * The `CancellationType` parameter
     * keeps the existing public signature alive.
     *
     * Forwards to `close()` with the seeded `legacy_cancellation` cause —
     * never an invented commercial cause — preserving the original type in
     * the cancellation metadata, and dispatches the legacy `@api`
     * `ServiceCancelled` event so existing listeners keep receiving it.
     *
     * THIS path's legacy notice computation reads
     * `article.metadata.service.cancellation_policy.notice_days` VERBATIM —
     * `ServiceLifecycleService::cancel()` reads a DIFFERENT metadata route;
     * there is deliberately no shared abstraction (gate round 8, P1 #1).
     *
     * The legacy `cancellation_requested_at` / `cancellation_effective_at` /
     * `refund_unused` columns are kept written until the AID-971 rewrite of
     * the close path retires them (§2.1).
     */
    public function cancel(CancellationType $type, ?string $reason = null): void
    {
        $this->triggerLegacyDeprecation(
            'ArticleServiceStatus::cancel()',
            'ArticleServiceStatus::close()/suspend() with the seeded `legacy_cancellation` cause',
        );

        $effectiveDate = match ($type) {
            CancellationType::IMMEDIATE     => now(),
            CancellationType::END_OF_PERIOD => $this->next_billing_date ?? now(),
            CancellationType::NOTICE_PERIOD => now()->addDays(
                $this->article->metadata['service']['cancellation_policy']['notice_days'] ?? 30
            ),
        };

        $userId = Auth::check() ? Auth::id() : null;

        $this->close(
            AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'legacy_cancellation'),
            $reason,
            $effectiveDate->isFuture() ? $effectiveDate : null,
        );

        // Legacy columns kept per §2.1 (untouched by the close API itself).
        $this->forceFill([
            'cancellation_requested_at' => now(),
            'cancellation_effective_at' => $effectiveDate,
            'refund_unused'             => $type->requiresRefund(),
        ])->save();

        $this->setMetadata('cancellation', array_merge($this->metadata['cancellation'] ?? [], [
            'reason'       => $reason,
            'requested_by' => $userId,
            'requested_at' => now()->toIso8601String(),
            'type'         => $type->value,
        ]));

        ServiceCancelled::dispatch($this->fresh() ?? $this, $type, $reason);
    }

    /**
     * ONE legacy-deprecation emitter for this class's shim paths (ADR-015 §5):
     * every delegating public path emits the same-shaped `E_USER_DEPRECATED`
     * through here. Internal code never calls legacy paths, so nothing
     * internal emits.
     */
    private function triggerLegacyDeprecation(string $method, string $replacement): void
    {
        trigger_error(sprintf(
            '%s is deprecated since larabill 7.0.0, use %s instead — see UPGRADE-7.0.md.',
            $method,
            $replacement,
        ), E_USER_DEPRECATED);
    }

    /**
     * Enter the transient guarantee window (ADR-015 §3.1/§3.4, slice 2
     * AID-953): the single `active → guarantee` edge, taken by
     * `GuaranteeService::openOperation()` when a refund claim is recorded.
     *
     * The state is TRANSIENT: only the recorded refund closes the agreement
     * (`GuaranteeService::confirmRefund()`, cause `client_request`). If the
     * refund is never confirmed, the agreement stays `guarantee` — no
     * automatic closure. No event is dispatched here: the registry row (not
     * a lifecycle broadcast) is this transition's record.
     *
     * @throws IllegalAgreementTransitionException on every other source state
     */
    public function enterGuarantee(): void
    {
        $this->applyTransition(AgreementStatus::GUARANTEE, function (ArticleServiceStatus $fresh): void {
            $metadata              = $fresh->metadata ?? [];
            $metadata['guarantee'] = array_merge($metadata['guarantee'] ?? [], [
                'entered_at' => now()->toIso8601String(),
            ]);

            $fresh->update([
                'status'   => AgreementStatus::GUARANTEE,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * This agreement's cadence as the ADR-015 value object. NULL for a
     * one-time agreement — the NULL cadence is a real state (legacy
     * `BillingFrequency::ONE_TIME`), not an absent one.
     *
     * A corrupted unit/quantity pair (outside the ADR-015 domain) throws
     * `InvalidBillingIntervalException` at read time — the data cannot be
     * scheduled, so the failure is loud, never silent.
     */
    public function billingInterval(): ?BillingInterval
    {
        return $this->billing_unit === null
            ? null
            : new BillingInterval($this->billing_unit, (int) $this->billing_quantity);
    }

    /**
     * Calculate the next billing date from the agreement's cadence.
     *
     * Resolves the ADR-015 interval (unit + quantity) and delegates to
     * `BillingInterval::addToDate()` — whose overflow semantics are PINNED to
     * the old `BillingFrequency::addToDate()` math (Jan 31 + 1 month → Mar 3),
     * so existing schedules do not shift (spec §2.4; invariance pinned in
     * tests/Unit/ValueObjects/BillingIntervalSchedulingInvarianceTest.php).
     *
     * A NULL cadence (one-time agreement) keeps the legacy ONE_TIME behaviour:
     * the date comes back unchanged.
     *
     * @deprecated since 7.0.0, use `BillingInterval::addToDate()` via `ArticleServiceStatus::billingInterval()` — see UPGRADE-7.0.md
     */
    public function calculateNextBillingDate(): Carbon
    {
        $currentDate = $this->next_billing_date ?? $this->started_at;

        if ($currentDate === null) {
            throw new \RuntimeException("Service {$this->id} has neither next_billing_date nor started_at to compute the next billing date.");
        }

        return $this->billingInterval()?->addToDate($currentDate) ?? $currentDate->copy();
    }

    /**
     * Revise the contractual price from the catalogue or an active override.
     *
     * This is an explicit act of the CONSUMER — a contract revision — and the
     * recurring engine never calls it (AID-956 D3). Invoking it from the
     * emission path would reprice live agreements without a contractual act,
     * which is exactly what `effective_price` exists to prevent.
     *
     * The revision only affects periods not yet emitted (D4): already emitted
     * lines are immutable, and their settlement measures on the emitted line.
     *
     * @throws MissingContractPriceException when neither an override nor a
     *                                       catalogue price yields a candidate. The previous price is kept.
     */
    public function updateEffectivePrice(): void
    {
        $override = $this->article->getActiveOverrideFor($this->customer_id);

        // Get price from ArticlePrice for the agreement's cadence (ADR-015
        // interval domain; a NULL cadence resolves the one-time price, exactly
        // as the legacy ONE_TIME lookup did).
        $basePrice = $this->article->getPriceForInterval($this->billingInterval());

        // No nullsafe here, deliberately: `??` already suppresses the
        // "property on null" warning for the whole left-hand expression
        // (verified on PHP 8.3), so `?->` would be redundant and PHPStan
        // rejects it. The AID-956 spec claimed this line worked by accident —
        // it did not; measured, the defect was only the missing guard below.
        $candidate = $override->custom_price
            ?? ($basePrice !== null ? FixedDecimal::ofUnscaled((int) $basePrice, 2) : null);

        // Compute first, write second: a revision with no candidate must leave
        // the agreement exactly as it was, never blank its price (D7).
        if ($candidate === null) {
            throw MissingContractPriceException::forRevision($this);
        }

        $this->update([
            'effective_price'     => $candidate,
            'current_override_id' => $override?->id,
        ]);
    }

    /**
     * Check if this service should be billed.
     *
     * Coarse model-level gate; the engine's precise window logic lives in
     * `RecurringBillingService::shouldGenerateInvoice()`. Composed of the
     * ADR-015 exclusions: only the `recurring` modality is ever billed
     * (`one_time` emits exactly once at contracting — with no
     * `next_billing_date` it never reaches here; `non_billable` never
     * emits at all), and NO period is ever emitted across a scheduled
     * close (§3.2) or a term end (§3.3).
     *
     * @deprecated since 7.0.0, use the state + interval gate (`AgreementStatus` modality + `billingInterval()`) — see UPGRADE-7.0.md
     */
    public function shouldBeBilled(): bool
    {
        if ($this->status !== AgreementStatus::ACTIVE
            || $this->next_billing_date === null
            || ! $this->next_billing_date->isPast()
            || ! $this->isRecurring()) {
            return false;
        }

        return ! $this->periodReachesBeyondScheduledClose()
            && ! $this->periodReachesBeyondExpiry();
    }

    /**
     * Whether the period starting at `next_billing_date` would reach beyond
     * a scheduled close (ADR-015 §3.2) — the emission gate's scheduled-close
     * exclusion. Always false without a scheduled close.
     */
    public function periodReachesBeyondScheduledClose(): bool
    {
        if ($this->close_scheduled_at === null || $this->next_billing_date === null) {
            return false;
        }

        $periodEnd = ($this->billingInterval()?->addToDate($this->next_billing_date->copy())
            ?? $this->next_billing_date->copy())->subDay();

        return $periodEnd->gt($this->close_scheduled_at);
    }

    /**
     * Whether the period starting at `next_billing_date` would reach beyond
     * the term end — the emission gate's closed-term exclusion (ADR-015
     * §3.3), the same shape as the scheduled-close exclusion above. Always
     * false without an `expires_at`: an auto-renewing agreement has no term
     * end to reach beyond. Reaching beyond is STRICT — the period that ends
     * exactly on the term date is still billed (the last period of a
     * 12-month plan that must not renew). Expiry closure itself is T2's
     * single locked materialiser's business; this predicate only refuses to
     * schedule past the term.
     */
    public function periodReachesBeyondExpiry(): bool
    {
        if ($this->expires_at === null || $this->next_billing_date === null) {
            return false;
        }

        $periodEnd = ($this->billingInterval()?->addToDate($this->next_billing_date->copy())
            ?? $this->next_billing_date->copy())->subDay();

        return $periodEnd->gt($this->expires_at);
    }

    /**
     * Whether this agreement carries the `recurring` modality (ADR-015
     * §2.1/§3.3) — the only modality the recurrent engine bills. `one_time`
     * emits exactly once at contracting; `non_billable` never emits.
     */
    public function isRecurring(): bool
    {
        return $this->modality === AgreementModality::RECURRING;
    }

    /**
     * Whether this agreement runs to a FIXED TERM (`renewal = closed_term`,
     * ADR-015 §3.3): the engine never advances `next_billing_date` past
     * `expires_at`, and the gate never bills a period reaching beyond it.
     * The backfill guarantees `closed_term ⟺ expires_at` non-null on
     * migrated rows; a consumer-created row should honour the same pair.
     */
    public function isClosedTerm(): bool
    {
        return $this->renewal === 'closed_term';
    }

    /**
     * Get days until next billing.
     */
    public function getDaysUntilNextBilling(): int
    {
        if (! $this->next_billing_date) {
            return 0;
        }

        return (int) max(0, now()->diffInDays($this->next_billing_date, false));
    }

    /**
     * Get instance data by key with optional default.
     */
    public function getInstanceData(string $key, mixed $default = null): mixed
    {
        return data_get($this->instance_data, $key, $default);
    }

    /**
     * Set instance data by key.
     */
    public function setInstanceData(string $key, mixed $value): void
    {
        $data = $this->instance_data ?? [];
        data_set($data, $key, $value);

        $this->update(['instance_data' => $data]);
    }

    /**
     * Get metadata by key with optional default.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Set metadata by key.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $data = $this->metadata ?? [];
        data_set($data, $key, $value);

        $this->update(['metadata' => $data]);
    }

    /**
     * Run one state transition under the ADR-015 §3.2 lock/revalidation
     * discipline: the row is re-read under `FOR UPDATE` and the CURRENT
     * (fresh) state is what the transition map judges — a stale in-memory
     * state can never authorize a transition. The write runs inside the
     * same transaction as the validation, so a rejected transition leaves
     * the agreement untouched.
     *
     * @param  callable(ArticleServiceStatus): void  $write  Receives the
     *                                                       fresh, locked instance and performs the update.
     */
    private function applyTransition(AgreementStatus $target, callable $write): ArticleServiceStatus
    {
        $fresh = DB::transaction(function () use ($target, $write): ArticleServiceStatus {
            /** @var ArticleServiceStatus $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->status->canTransitionTo($target)) {
                throw IllegalAgreementTransitionException::transition($fresh->status, $target);
            }

            $write($fresh);

            return $fresh;
        });

        // The in-memory instance follows the persisted state (a stale model
        // must not re-write the old status on a later save).
        $this->refresh();

        return $fresh;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return ArticleServiceStatusFactory
     */
    protected static function newFactory()
    {
        return ArticleServiceStatusFactory::new();
    }
}
