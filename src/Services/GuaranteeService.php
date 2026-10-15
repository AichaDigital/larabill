<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Exceptions\GuaranteeOperationPendingException;
use AichaDigital\Larabill\Exceptions\IllegalAgreementTransitionException;
use AichaDigital\Larabill\Exceptions\InvalidSettlementAmountException;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\AgreementGuaranteeOperation;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Guarantee service — the ADR-015 §2.6/§3.4 guarantee feature (slice 2,
 * AID-953): per-product refund windows with a transient `guarantee` state,
 * an operations registry, and rectificative wiring through the AID-971
 * settlement service (`SettlementService::rectify()`).
 *
 * REGISTRY ONLY (§2.6): larabill records what happened; the CONSUMER
 * decides client policy. In particular, eligibility BY DATE is never
 * enforced here — `guaranteeWindowEndsAt()` only exposes the window math
 * (window end = agreement `started_at` + the frozen `guarantee_days`
 * snapshot); whether a claim is within the window is the consumer's decision
 * to make BEFORE calling `openOperation()`. No invented policy.
 *
 * THE FLOW (§3.4): `openOperation()` (registry `pending` + agreement →
 * `guarantee`) → `settle()` (rectificative via the settlement service) →
 * `confirmRefund()` (registry `refunded` + agreement `closed` with cause
 * `client_request`). If the refund is never confirmed, the agreement stays
 * `guarantee` — no automatic closure.
 *
 * SETTLEMENT IS IDEMPOTENT THROUGH COLLISION-AND-ADOPTION (gate rounds
 * 10–13): the pending registry row's UUID yields the deterministic
 * `settlement_operation_key` = `guarantee_ops:{registry_row_uuid}`, carried
 * by a UNIQUE index on `invoices`. Phase 1 issues in an INDEPENDENT
 * transaction — no registry lock is held across it (on Laravel's default
 * connection a nested independent transaction is a savepoint, so a lock
 * could not coexist with the commit); a concurrent issuer of the same claim
 * collides on the UNIQUE index, the loser adopts instead of issuing. Phase 2
 * completes the registry in a SEPARATE transaction under `FOR UPDATE` on the
 * row, revalidating it is still `pending`. No interleaving can issue two
 * fiscal corrections for one claim: the unique index is the serialisation
 * point. The interleaving is proven by
 * tests/Concurrency/GuaranteeSettlementConcurrencyTest.php.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class GuaranteeService
{
    /**
     * The §3.4 deterministic operation key prefix. The full key is
     * `{prefix}{registry_row_uuid}` — every requester of the same claim
     * resolves the SAME key.
     */
    public const OPERATION_KEY_PREFIX = 'guarantee_ops:';

    public function __construct(
        protected SettlementService $settlementService,
        protected InvoiceService $invoiceService,
    ) {}

    /**
     * The §3.4 deterministic operation key of a pending registry row.
     */
    public function operationKey(AgreementGuaranteeOperation $operation): string
    {
        return self::OPERATION_KEY_PREFIX.$operation->getKey();
    }

    /**
     * Open a guarantee operation: record the registry row `pending` and move
     * the agreement into the transient `guarantee` state, atomically.
     *
     * Validations (derived from the §3.4 flow, no invented policy):
     *
     * - `$amount` is a strictly positive base-100 refund amount (the
     *   rectificative issues it negated; zero is "no artifact" — the
     *   AID-971 amount doctrine);
     * - the invoice belongs to the agreement's billable context: its
     *   `billable_user_id` must be the agreement's customer. The rectificative
     *   corrects an operation with the original's frozen recipient — a
     *   foreign invoice cannot be the ADR-014 original of THIS agreement's
     *   refund;
     * - the agreement is ACTIVE and can take the §3.1 `active → guarantee`
     *   edge — enforced by the locked transition path inside the same
     *   transaction, so a rejected transition rolls the registry row back.
     *
     * Deliberately NOT validated here (consumer policy, registry-only
     * stance): whether the claim is within the window (eligibility by date),
     * whether the invoice is fully paid, whether the client deserves the
     * refund. The original's own fiscal state (draft/proforma/annulled) is
     * refused loud at `settle()` time by the settlement service's guards.
     *
     * A concurrent duplicate for the same agreement (a pending operation
     * already holding the sentinel) collides on the UNIQUE
     * `(agreement_id, pending_slot)` index and is refused with
     * `GuaranteeOperationPendingException` — one pending operation per
     * agreement, enforced by the database, not by a check-then-insert.
     *
     * @param  ArticleServiceStatus  $agreement  an ACTIVE agreement with the guarantee snapshot in place
     * @param  Invoice  $originalInvoice  the emitted invoice the refund corrects
     * @param  int  $amount  base-100 refund amount, strictly positive
     *
     * @throws InvalidSettlementAmountException on a non-positive amount
     * @throws InvalidArgumentException when the invoice is outside the agreement's billable context
     * @throws GuaranteeOperationPendingException when the agreement already has a pending operation
     * @throws IllegalAgreementTransitionException when the agreement cannot take the `guarantee` edge
     */
    public function openOperation(ArticleServiceStatus $agreement, Invoice $originalInvoice, int $amount): AgreementGuaranteeOperation
    {
        if ($amount <= 0) {
            throw InvalidSettlementAmountException::forAmount($amount);
        }

        if ((string) $originalInvoice->billable_user_id !== (string) $agreement->customer_id) {
            throw new InvalidArgumentException(sprintf(
                'Invoice [%s] does not belong to the billable context of agreement #%s '
                .'(invoice billable_user_id %s, agreement customer_id %s). Only the agreement\'s own '
                .'invoices can be corrected by its guarantee refund (ADR-015 §3.4).',
                $originalInvoice->fiscal_number,
                (string) $agreement->getKey(),
                (string) $originalInvoice->billable_user_id,
                (string) $agreement->customer_id,
            ));
        }

        return DB::transaction(function () use ($agreement, $originalInvoice, $amount): AgreementGuaranteeOperation {
            try {
                $operation = AgreementGuaranteeOperation::create([
                    'agreement_id'        => $agreement->id,
                    'original_invoice_id' => $originalInvoice->id,
                    'amount'              => FixedDecimal::ofUnscaled($amount, 2),
                    'status'              => AgreementGuaranteeOperation::STATUS_PENDING,
                    'pending_slot'        => AgreementGuaranteeOperation::PENDING_SLOT,
                ]);
            } catch (QueryException $e) {
                if (self::isUniqueViolation($e)) {
                    throw GuaranteeOperationPendingException::alreadyPending($agreement);
                }

                throw $e;
            }

            // The locked transition path (§3.1 `active → guarantee`): the row
            // is re-read under FOR UPDATE and the CURRENT state is what the
            // transition map judges. A rejected transition aborts THIS
            // transaction and rolls the registry row back with it.
            $agreement->enterGuarantee();

            return $operation;
        });
    }

    /**
     * Settle a pending guarantee operation: issue the rectificative and
     * record it on the registry. The refund is NOT confirmed here — the
     * registry stays `pending` and the agreement stays `guarantee` until the
     * consumer calls `confirmRefund()`.
     *
     * Two-phase collision-and-adoption (§3.4, gate rounds 10–13):
     *
     * - **Phase 1 — issuance, INDEPENDENT transaction.** The rectificative is
     *   issued via `SettlementService::rectify()` (which runs its own
     *   transaction) stamped with the deterministic operation key. NO
     *   registry lock is held across it — on the default connection a nested
     *   independent transaction is a savepoint, so a lock could not survive
     *   the commit. A concurrent issuer of the same claim collides on the
     *   UNIQUE `settlement_operation_key` index: the loser does NOT issue and
     *   proceeds straight to adoption. Any OTHER failure means nothing was
     *   issued — a retry simply issues.
     * - **Phase 2 — registry completion, SEPARATE transaction** under `FOR
     *   UPDATE` on the registry row: revalidate it is still `pending` (else
     *   abort — idempotent no-op), find the rectificative by key (own
     *   issuance or the winner's — identical outcome) and record
     *   `rectifies_invoice_id`.
     *
     * @return Invoice|null the rectificative issued or adopted; null when the
     *                      operation was already refunded and carries no link
     *                      (nothing to re-report)
     */
    public function settle(AgreementGuaranteeOperation $operation): ?Invoice
    {
        $operation = $operation->fresh() ?? $operation;

        // Fast path: an already-completed operation is an idempotent no-op —
        // the recorded artifact is re-reported, never issued twice. Phase 2
        // would reach the same result through revalidation; this only avoids
        // a doomed issuance attempt.
        if ($operation->isRefunded()) {
            return $operation->rectifies_invoice_id !== null
                ? Invoice::query()->findOrFail($operation->rectifies_invoice_id)
                : null;
        }

        $key      = $this->operationKey($operation);
        $original = Invoice::query()->findOrFail($operation->original_invoice_id);

        // ── Phase 1: issuance, independent transaction ──────────────────────
        try {
            $this->settlementService->rectify($original, -$operation->amount->unscaledValue(), $key);
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                // Nothing was issued — a retry simply issues.
                throw $e;
            }

            // A concurrent issuer of the same claim won the UNIQUE index and
            // has already committed: do NOT issue, adopt by key in Phase 2.
        }

        // ── Phase 2: registry completion, separate transaction ──────────────
        return $this->completeRegistry($operation, $key);
    }

    /**
     * Confirm the refund of a guarantee operation — the §3.4 flow step the
     * CONSUMER triggers: the registry flips to `refunded` (`refunded_at`,
     * sentinel released: `pending_slot` → NULL) and the agreement closes
     * with the seeded `client_request` cause through the locked transition
     * path (`guarantee → closed`, §3.1).
     *
     * Registry-only stance: there is deliberately NO precondition that a
     * rectificative was issued — the consumer owns the flow order and may
     * refund out-of-band; the registry records what it is told. Re-confirming
     * an already-refunded operation is an idempotent no-op.
     */
    public function confirmRefund(AgreementGuaranteeOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            /** @var AgreementGuaranteeOperation $fresh */
            $fresh = AgreementGuaranteeOperation::query()
                ->whereKey($operation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->isPending()) {
                return; // idempotent no-op
            }

            $fresh->update([
                'status'       => AgreementGuaranteeOperation::STATUS_REFUNDED,
                'refunded_at'  => now(),
                'pending_slot' => null, // the sentinel releases
            ]);

            // The §3.1 edge `guarantee → closed`, with the §2.2 seeded
            // `client_request` cause — the only closure cause the refund flow
            // establishes. A rejected transition aborts this transaction and
            // rolls the registry flip back with it.
            $agreement = ArticleServiceStatus::query()->findOrFail($fresh->agreement_id);
            $agreement->close(AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'client_request'));
        });

        $operation->refresh();
    }

    /**
     * The end of this agreement's guarantee window: `started_at` + the
     * FROZEN `guarantee_days` snapshot (§3.4, gate round 6 P2 — the window
     * anchor and length were fixed at contracting; changing the product's
     * `guarantee_days` later never moves an existing right). NULL or zero
     * snapshot means no window (§2.1: NULL/0 = no window).
     *
     * The returned instant is the window's end boundary; whether a claim is
     * within it remains the CONSUMER's eligibility decision (registry-only
     * stance) — this helper exposes the math, it enforces nothing.
     */
    public function guaranteeWindowEndsAt(ArticleServiceStatus $agreement): ?CarbonInterface
    {
        $days = $agreement->guarantee_days;

        if ($days === null || $days <= 0 || $agreement->started_at === null) {
            return null;
        }

        return $agreement->started_at->copy()->addDays($days);
    }

    /**
     * Phase 2 — complete the registry under `FOR UPDATE` on the row
     * (separate transaction from the issuance): revalidate still `pending`
     * (else abort — idempotent no-op), find the rectificative by key (own
     * issuance or the winner's — identical outcome) and record the link.
     */
    private function completeRegistry(AgreementGuaranteeOperation $operation, string $key): ?Invoice
    {
        return DB::transaction(function () use ($operation, $key): ?Invoice {
            /** @var AgreementGuaranteeOperation $fresh */
            $fresh = AgreementGuaranteeOperation::query()
                ->whereKey($operation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->isPending()) {
                // Someone else completed (or refunded) it between our Phase 1
                // and this lock — idempotent no-op, re-report their artifact.
                return $fresh->rectifies_invoice_id !== null
                    ? Invoice::query()->findOrFail($fresh->rectifies_invoice_id)
                    : null;
            }

            $rectificative = Invoice::query()->where('settlement_operation_key', $key)->first();

            if ($rectificative === null) {
                // Unreachable through the public flow: Phase 1 either issued
                // (its commit precedes this transaction) or collided with a
                // committed winner. Defensive and loud — never a silent
                // pending row with a half-settled claim.
                throw new RuntimeException(sprintf(
                    'Guarantee operation [%s] has no rectificative to adopt for key [%s]: issuance '
                    .'neither committed nor collided with a committed winner. Nothing was issued; '
                    .'retry the settlement.',
                    (string) $fresh->getKey(),
                    $key,
                ));
            }

            // ADR-016 implementation-gate finding 3 (2026-10-13): with the
            // two-phase issuance, a winning claim can have committed ONLY its
            // phase-1 durable DRAFT when a loser reaches this lookup — the
            // winner's phase 2 may still be in flight or have failed. The key
            // makes that DRAFT this settlement's artifact: resume its issuance
            // (idempotent — a winner that committed in between returns itself
            // via issue()'s early return) instead of linking an unnumbered
            // document as the rectificative. Anything else that is not ISSUED
            // is not a claim artifact — loud, never linked.
            if ($rectificative->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
                if ($rectificative->invoice_document_status !== InvoiceDocumentStatus::DRAFT) {
                    throw new RuntimeException(sprintf(
                        'Guarantee operation [%s]: the rectificative for key [%s] is in document status [%s], '
                        .'not ISSUED or DRAFT — it cannot be adopted as the settlement artifact.',
                        (string) $fresh->getKey(),
                        $key,
                        (string) $rectificative->invoice_document_status?->name,
                    ));
                }

                $rectificative = $this->invoiceService->issue($rectificative);
            }

            $fresh->update(['rectifies_invoice_id' => $rectificative->id]);

            return $rectificative;
        });
    }

    /**
     * Whether a QueryException is a unique/constraint violation (SQLSTATE
     * 23000 on MySQL, MariaDB and SQLite alike) — the collision signal of
     * both serialisation points (the pending sentinel and the settlement
     * operation key).
     */
    private static function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
