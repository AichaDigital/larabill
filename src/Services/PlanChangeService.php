<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AgreementModality;
use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Exceptions\IllegalAgreementTransitionException;
use AichaDigital\Larabill\Exceptions\MissingContractPriceException;
use AichaDigital\Larabill\Exceptions\PlanChangeNotAllowedException;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Plan change (ADR-015 §3.5, AID-953 slice 3): close the current agreement
 * — liquidating its unconsumed proportion through the ADR-014 settlement
 * service — and create the successor agreement for the new plan.
 *
 * THE POLICY (§3.5 config contract). `larabill.agreements.plan_change.policy`
 * is `restart` (default: the new plan runs its full term from the change
 * date) or `align_end` (the successor runs only until the predecessor's
 * term end, which requires a closed-term predecessor). Per the AID-589
 * lesson the effective default lives AT THE READ POINT
 * (`config('larabill.agreements.plan_change.policy', 'restart')`):
 * `mergeConfigFrom` is shallow, so a consumer whose published config
 * predates the key still gets the spec default. Any other value — from the
 * config or an explicit override — throws
 * `PlanChangeNotAllowedException::unknownPolicy()` loud; nothing is ever
 * silently reinterpreted.
 *
 * ELIGIBILITY (gate rounds 6 P2 / 7 P1 #4 / 10 P2). Only `active`
 * agreements with NO `close_scheduled_at` are plan-changed, revalidated
 * INSIDE the lock:
 *
 * - `suspended` → typed refusal (reinstate or close first);
 * - `guarantee` → typed refusal (the guarantee completes its refund first);
 * - scheduled close → typed refusal (let it materialise, or
 *   `ArticleServiceStatus::cancelScheduledClose()` first — §3.2);
 * - `align_end` requires `renewal = closed_term` WITH a non-null
 *   `expires_at`: an auto-renewing agreement has no previous end date to
 *   align to — the combination is rejected, never reinterpreted;
 * - `closed` is NOT a refusal: it is the idempotent no-op of the atomic
 *   boundary (below) — `changePlan()` returns null.
 *
 * THE ATOMIC/IDEMPOTENT BOUNDARY (gate round 6 P1). The whole flow runs in
 * ONE DB transaction holding `FOR UPDATE` on the agreement row with the
 * state revalidation INSIDE the lock — the same pattern as the emission
 * boundary (`RecurringBillingService::emitWithinBoundary()`). Two
 * concurrent plan changes on the same agreement: the second revalidates
 * inside the lock, sees the row already `closed`, and aborts WITHOUT
 * issuing anything. A serial test alone does not prove this; the real
 * interleaving is pinned by tests/Concurrency/PlanChangeConcurrencyTest.php.
 *
 * THE FLOW (both policies):
 *
 * 1. Close the current agreement through the close/suspend API (§3.2) —
 *    the ONE closure path, cause optional. The cause is a CALLER input:
 *    the spec declares no dedicated plan-change cause in the §2.2 closed
 *    vocabulary, and causes are never invented by the package (§2.2:
 *    "closed + NULL cause is a legal state"), so `changePlan()` forwards
 *    the caller's `closureCause` (validated as an active closed-scope
 *    cause by the close API) or leaves NULL.
 * 2. Liquidate the unconsumed proportion via
 *    `SettlementService::settleUnconsumed()` when the caller supplies a
 *    positive amount: the F1-with-credit-line artifact of ADR-014 — an
 *    ordinary invoice, `rectifies_invoice_id` NULL, current fiscal context
 *    on the abono line (NOT `rectify()`, which would reclassify it R1 — a
 *    defect). The amount/items are INPUTS the same way the settlement
 *    service's are: the proportion is computed by the consumer or via
 *    `SettlementArithmetic` (the pure calculator); NO derivation is
 *    invented here beyond what the settlement service already owns. A null
 *    or zero amount means nothing to liquidate — no artifact is issued
 *    (a zero abono does not exist; AID-971 amount doctrine).
 * 3. Create the successor agreement through the model's ordinary creation
 *    path — a NEW CONTRACT: its own `started_at` (the change date) and its
 *    own guarantee snapshot taken from the product's CURRENT
 *    `guarantee_days`; the predecessor's window does NOT transfer (gate
 *    round 7 S1, decided). `restart` → `renewal = auto`, no `expires_at`
 *    (the full term from the change date; a consumer wanting a fixed term
 *    sets it through its own contract management). `align_end` →
 *    `renewal = closed_term` ending at the predecessor's `expires_at`.
 *    The successor's contractual price is the explicit input when given,
 *    else the new plan's catalogue price for the cadence — the same
 *    resolution any contract creation uses, never the predecessor's
 *    effective_price; with neither, `MissingContractPriceException` (a
 *    zero price is valid and is billed as zero, AID-956 doctrine).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class PlanChangeService
{
    /** The §3.5 policy domain. Anything else is `unknownPolicy()` loud. */
    public const POLICY_RESTART  = 'restart';

    public const POLICY_ALIGN_END = 'align_end';

    public function __construct(
        protected SettlementService $settlementService
    ) {}

    /**
     * Change the plan of an active agreement: close it (optionally
     * liquidating its unconsumed proportion) and create the successor
     * contract for the new plan.
     *
     * @param  ArticleServiceStatus  $agreement  the agreement to change. Only
     *                                           `active` with no `close_scheduled_at` is eligible
     *                                           (revalidated INSIDE the lock); `closed` is the
     *                                           idempotent no-op returning null
     * @param  Article  $newPlan  the product the successor contract is for. Its
     *                            CURRENT `guarantee_days` becomes the successor's own
     *                            guarantee snapshot (the predecessor's window does not
     *                            transfer)
     * @param  BillingInterval  $newCadence  the successor's billing cadence
     *                                       (ADR-015 domain: months 1-12 / years 1-10)
     * @param  int|null  $successorEffectivePrice  the successor's contractual
     *                                             price, base-100 unscaled (zero valid). Null resolves
     *                                             it from the new plan's catalogue price for the cadence;
     *                                             neither present → MissingContractPriceException
     * @param  string|null  $policy  `restart`|`align_end`; null reads
     *                               `config('larabill.agreements.plan_change.policy', 'restart')`
     *                               at the read point. Anything else throws typed loud
     * @param  CarbonInterface|null  $effectiveAt  the change date — the
     *                                             successor's `started_at` and `next_billing_date`.
     *                                             Defaults to today. The predecessor's close is always
     *                                             immediate (a future plan change is not a §3.5 flow;
     *                                             a consumer schedules one by closing first and calling
     *                                             this later)
     * @param  Invoice|null  $settleInvoice  the emitted invoice whose
     *                                       unconsumed proportion is liquidated (required when
     *                                       `$settleAmount` is positive)
     * @param  int|null  $settleAmount  base-100 unconsumed value to liquidate,
     *                                  strictly positive to issue the abono; null/0 issues NO
     *                                  artifact (nothing unconsumed, or the consumer decided none)
     * @param  list<array<string, mixed>>  $additionalItems  additional ordinary
     *                                                       charge lines riding the abono document (e.g. the new
     *                                                       plan's charge — the consumer's orchestration choice,
     *                                                       ADR-014 §2), forwarded verbatim to
     *                                                       `SettlementService::settleUnconsumed()`
     * @param  bool  $isRoiTaxed  the CURRENT reverse-charge qualification of
     *                            the liquidation emission (AID-929: consumer-declared),
     *                            forwarded verbatim
     * @param  int|null  $creditArticleId  explicit article for the credit line,
     *                                     forwarded verbatim (the settlement service's
     *                                     explicit-requirement on ambiguous fiscal groups applies)
     * @param  AgreementCause|null  $closureCause  the closed-scope cause for the
     *                                             predecessor's closure; null is a legal state (§2.2 —
     *                                             causes are never invented by the package). Scope and
     *                                             active-state validation is the close API's
     * @param  string|null  $note  the closure note, forwarded to the close API
     * @return ArticleServiceStatus|null the successor agreement, or null when
     *                                   the agreement was already closed (idempotent no-op —
     *                                   nothing issued, nothing changed)
     *
     * @throws PlanChangeNotAllowedException on every eligibility refusal and
     *                                       on an out-of-domain policy value
     * @throws IllegalAgreementTransitionException when the agreement is not in
     *                                             an active-eligible state (e.g. `pending`)
     * @throws MissingContractPriceException when neither an explicit price nor
     *                                       a catalogue price yields the successor's contractual price
     * @throws InvalidArgumentException when a positive `$settleAmount` comes
     *                                  without the invoice to liquidate
     */
    public function changePlan(
        ArticleServiceStatus $agreement,
        Article $newPlan,
        BillingInterval $newCadence,
        ?int $successorEffectivePrice = null,
        ?string $policy = null,
        ?CarbonInterface $effectiveAt = null,
        ?Invoice $settleInvoice = null,
        ?int $settleAmount = null,
        array $additionalItems = [],
        bool $isRoiTaxed = false,
        ?int $creditArticleId = null,
        ?AgreementCause $closureCause = null,
        ?string $note = null,
    ): ?ArticleServiceStatus {
        // Input validation BEFORE any write: the policy domain and the
        // liquidation input pairing are caller contracts, not state.
        $policyName = $this->resolvePolicy($policy);

        $changeDate = $effectiveAt !== null
            ? Carbon::instance($effectiveAt)->startOfDay()
            : now()->startOfDay();

        if ($settleAmount !== null && $settleAmount > 0 && $settleInvoice === null) {
            throw new InvalidArgumentException(
                'changePlan received a settlement amount ('.$settleAmount.') without the invoice to liquidate: '
                .'the abono liquidates the unconsumed proportion of an EMITTED invoice (ADR-014) — pass '
                .'settleInvoice alongside a positive settleAmount, or no amount at all to skip the liquidation.'
            );
        }

        return DB::transaction(function () use (
            $agreement,
            $newPlan,
            $newCadence,
            $successorEffectivePrice,
            $policyName,
            $changeDate,
            $settleInvoice,
            $settleAmount,
            $additionalItems,
            $isRoiTaxed,
            $creditArticleId,
            $closureCause,
            $note,
        ): ?ArticleServiceStatus {
            /** @var ArticleServiceStatus $fresh */
            $fresh = ArticleServiceStatus::query()
                ->whereKey($agreement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent no-op (gate round 6 P1): a re-invocation — or the
            // loser of a concurrent race — revalidates INSIDE the lock and
            // finds the row already closed. It aborts WITHOUT issuing
            // anything: no successor, no liquidation, no closure write.
            if ($fresh->status === AgreementStatus::CLOSED) {
                return null;
            }

            $this->assertEligible($fresh, $policyName);

            // 1. Close through the ONE closure path (§3.2): the locked
            // transition revalidates the state again and writes the closure
            // metadata. The cause/note are caller inputs; NULL cause legal.
            $fresh->close($closureCause, $note);

            // 2. Liquidate the unconsumed proportion through the ADR-014
            // settlement service — the F1-with-credit-line artifact (an
            // ordinary invoice, rectifies_invoice_id NULL, current fiscal
            // context), never the rectificative path (which would
            // reclassify it R1 — a defect per §3.5).
            if ($settleAmount !== null && $settleAmount > 0) {
                $this->settlementService->settleUnconsumed(
                    $settleInvoice,
                    $settleAmount,
                    $additionalItems,
                    $isRoiTaxed,
                    $creditArticleId,
                );
            }

            // 3. The successor: a NEW contract through the model's ordinary
            // creation path — own started_at, own guarantee snapshot.
            return $this->createSuccessor($fresh, $newPlan, $newCadence, $policyName, $changeDate, $successorEffectivePrice);
        });
    }

    /**
     * The §3.5 eligibility gate, judged against the FRESH (locked) state —
     * a stale in-memory state can never authorize a plan change.
     */
    private function assertEligible(ArticleServiceStatus $fresh, string $policyName): void
    {
        if ($fresh->status === AgreementStatus::SUSPENDED) {
            throw PlanChangeNotAllowedException::suspended($fresh);
        }

        if ($fresh->status === AgreementStatus::GUARANTEE) {
            throw PlanChangeNotAllowedException::inGuarantee($fresh);
        }

        if ($fresh->status !== AgreementStatus::ACTIVE) {
            // `pending` and any future state: the operation requires active.
            throw IllegalAgreementTransitionException::requiresActive($fresh->status);
        }

        if ($fresh->close_scheduled_at !== null) {
            throw PlanChangeNotAllowedException::closeScheduled($fresh);
        }

        if ($policyName === self::POLICY_ALIGN_END
            && (! $fresh->isClosedTerm() || $fresh->expires_at === null)) {
            // An auto-renewing agreement (or a closed-term one whose end date
            // is missing) has no previous end date to align to (gate round
            // 10 P2) — rejected, never silently reinterpreted.
            throw PlanChangeNotAllowedException::alignEndRequiresClosedTerm($fresh);
        }
    }

    /**
     * The policy value at the read point (AID-589): the config default is
     * read HERE, not relied upon from mergeConfigFrom — a consumer whose
     * published config predates the key gets the spec default. Any value
     * outside the domain throws typed loud.
     */
    private function resolvePolicy(?string $override): string
    {
        /** @var mixed $value */
        $value = $override ?? config('larabill.agreements.plan_change.policy', self::POLICY_RESTART);

        if ($value !== self::POLICY_RESTART && $value !== self::POLICY_ALIGN_END) {
            throw PlanChangeNotAllowedException::unknownPolicy((string) $value);
        }

        return (string) $value;
    }

    /**
     * Create the successor agreement through the model's ordinary creation
     * path — the SAME path consumers use (`ArticleServiceStatus::create`),
     * not a parallel mechanism.
     */
    private function createSuccessor(
        ArticleServiceStatus $predecessor,
        Article $newPlan,
        BillingInterval $cadence,
        string $policyName,
        CarbonInterface $changeDate,
        ?int $successorEffectivePrice,
    ): ArticleServiceStatus {
        $alignEnd = $policyName === self::POLICY_ALIGN_END;

        return ArticleServiceStatus::query()->create([
            'customer_id'        => $predecessor->customer_id,
            'article_id'         => $newPlan->id,
            // The plan change re-contracts the SAME service instance.
            'instance_identifier' => $predecessor->instance_identifier,
            'instance_name'       => $predecessor->instance_name,
            'started_at'          => $changeDate->toDateString(),
            'next_billing_date'   => $changeDate->toDateString(),
            // restart: full term from the change date (auto, no forced end).
            // align_end: only until the predecessor's term end (closed_term).
            'renewal'            => $alignEnd ? 'closed_term' : 'auto',
            'expires_at'         => $alignEnd ? $predecessor->expires_at?->toDateString() : null,
            'status'             => AgreementStatus::ACTIVE,
            'modality'           => AgreementModality::RECURRING,
            'billing_unit'       => $cadence->unit,
            'billing_quantity'   => $cadence->quantity,
            // Gate round 7 S1 (decided): the successor freezes its OWN
            // guarantee snapshot from the product's CURRENT value — the
            // predecessor's window does not transfer.
            'guarantee_days'     => $newPlan->guarantee_days,
            'effective_price'    => FixedDecimal::ofUnscaled(
                $successorEffectivePrice ?? $this->resolveContractPrice($newPlan, $cadence),
                2,
            ),
            'metadata'           => [
                'plan_change' => [
                    'predecessor_agreement_id' => $predecessor->getKey(),
                    'policy'                   => $policyName,
                    'changed_at'               => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * The successor's contractual price when the caller passed none: the
     * new plan's catalogue price for the requested cadence — the same
     * resolution any contract creation uses (`updateEffectivePrice()`'s
     * lookup), never the predecessor's effective_price. Absent is loud.
     */
    private function resolveContractPrice(Article $newPlan, BillingInterval $cadence): int
    {
        $cataloguePrice = $newPlan->getPriceForInterval($cadence);

        if ($cataloguePrice === null) {
            throw MissingContractPriceException::forNewAgreement(
                (string) ($newPlan->name ?? $newPlan->getKey()),
                $cadence->label(),
            );
        }

        return (int) $cataloguePrice;
    }
}
