<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\CreditOperationKind;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\SaleIntegrityReason;
use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\InvoiceNotCancellableException;
use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\CreditOperation;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\VoucherSaleClaim;
use AichaDigital\Larabill\Services\Payments\ExitBands;
use AichaDigital\Larabill\Services\Payments\Ledger;
use AichaDigital\Larabill\Services\Payments\OutermostTransaction;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Support\CheckedWrite;
use AichaDigital\Larabill\ValueObjects\SaleRecoveryRow;
use AichaDigital\Larabill\ValueObjects\VoucherConsumptionRequest;
use AichaDigital\Larabill\ValueObjects\VoucherConsumptionResult;
use AichaDigital\Larabill\ValueObjects\VoucherReceiptData;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use AichaDigital\Larabill\ValueObjects\VoucherSaleResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The voucher sale (AID-1825 minimal scope §1, §1a, §3): accepted proforma →
 * exact payment → voucher-sale invoice → balance.
 *
 * Four stages, each resumable by the same operation key:
 *
 *   S0+S1  under the proforma lock: classify (§2) and, for a sale, record
 *          the payment with NO credit entry and write the CLAIM with its
 *          frozen expectation (amount, currency, billable user).
 *   S2     convert the proforma; the PREPARED invoice gets `sale_claim_id`
 *          in the same transaction.
 *   S3a    revalidate (§1a rule 4), stage → ISSUING, then `issue()` under
 *          the sale authorization — `issue()` writes the sale mark in its
 *          own transaction and runs the optional hook.
 *   S3b    one transaction: revalidate, no competing allocation, collection
 *          DRAFT → PENDING, ONE allocation of the full payment,
 *          recompute → PAID, ONE voucher entry, stage → BACKED, and the
 *          WHOLE TUPLE re-read before commit (§1a rule 3).
 *
 * A re-call with the same key resumes at the first stage not yet done and
 * re-runs every check; an incoherent sale throws SaleIntegrityException with
 * zero writes (§3). Must be called OUTSIDE the consumer's transaction: the
 * stages commit separately on purpose (S3a's issue() is its own retrying
 * transaction; AID-570).
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final class VoucherSaleService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @throws SaleIntegrityException when a re-call finds the sale incoherent (zero writes)
     * @throws IdempotencyConflictException when the key names another payload
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     */
    public function sellVoucher(VoucherSaleRequest $request): VoucherSaleResult
    {
        OutermostTransaction::assert(__METHOD__);

        $claimed = $this->claim($request);
        if ($claimed instanceof VoucherSaleResult) {
            return $claimed; // a named refusal: nothing was written
        }

        $claim   = $claimed;
        $invoice = $this->convert($claim);
        $invoice = $this->issueSale($claim, $invoice);

        return $this->back($claim, $invoice);
    }

    /**
     * S0+S1 — classify under the proforma lock; for a sale, record the
     * payment (no entry) and write the claim. Returns the claim to resume
     * (new or replayed) or a refused result.
     */
    private function claim(VoucherSaleRequest $request): VoucherSaleClaim|VoucherSaleResult
    {
        return DB::transaction(function () use ($request): VoucherSaleClaim|VoucherSaleResult {
            // FIRST statement: the proforma row lock — the serialization point of every sale of this proforma.
            $proforma = Invoice::query()->whereKey($request->proformaId)->lockForUpdate()->first();

            if ($proforma === null || $proforma->serie !== InvoiceSerieType::PROFORMA) {
                throw new InvalidArgumentException("Voucher sale: [{$request->proformaId}] is not a proforma.");
            }

            $existing = VoucherSaleClaim::query()->where('proforma_id', $proforma->id)->first();
            if ($existing !== null) {
                // Any other key is a second payment for one sale.
                if ($existing->operation_key !== $request->operationKey) {
                    return VoucherSaleResult::refused(VoucherSaleOutcome::Duplicate);
                }

                // The same key resumes its own sale — with the SAME payload
                // (gate round 1 B2): amount, currency and payer against the
                // frozen expectation, and the payment's own identity.
                $payment = GroupedPayment::query()->whereKey($existing->grouped_payment_id)->first();
                if ($request->amount->toScale(2)->compareTo($existing->expected_amount) !== 0
                    || $request->currency                                               !== $existing->expected_currency
                    || $request->billableUserId                                         !== $existing->expected_billable_user_id
                    || $payment === null
                    || $payment->method    !== $request->method
                    || $payment->reference !== $request->reference
                    // the receipt instant to the second in UTC — the identity receive()'s Full replay uses (gate round 2 B5)
                    || $payment->paid_at->copy()->utc()->format('Y-m-d H:i:s') !== Carbon::instance($request->receivedAt)->utc()->format('Y-m-d H:i:s')) {
                    throw IdempotencyConflictException::forKey($request->operationKey);
                }

                return $existing;
            }

            // The same key on ANOTHER proforma is another payload under one key.
            if (VoucherSaleClaim::query()->where('operation_key', $request->operationKey)->exists()) {
                throw IdempotencyConflictException::forKey($request->operationKey);
            }

            // A key whose payment was reversed (reverse() at PENDING released the
            // claim) is spent: a re-payment needs a new key — the receive() rule.
            $recorded = GroupedPayment::query()->where('idempotency_key', $request->operationKey)->first();
            if ($recorded !== null && $recorded->isReversed()) {
                throw IdempotencyConflictException::keySpentByReversal($request->operationKey);
            }

            // §3: a key still recorded as a POSTED sale payment whose claim is
            // gone is a LOST CLAIM — identifiable, refused with a clear error, zero writes.
            if ($recorded !== null) {
                $invoiceId = Invoice::query()->where('proforma_id', $proforma->id)->whereNotNull('sale_claim_id')->value('id');

                throw SaleIntegrityException::because(SaleIntegrityReason::ClaimMissing, 'unknown', $invoiceId !== null ? (string) $invoiceId : null, "payment key {$request->operationKey} is recorded but its sale claim no longer exists");
            }

            if ((string) $proforma->billable_user_id !== $request->billableUserId) {
                return VoucherSaleResult::refused(VoucherSaleOutcome::PayerMismatch);
            }

            if (in_array($proforma->status, [InvoiceStatus::CONVERTING, InvoiceStatus::CONVERTED], true)) {
                return VoucherSaleResult::refused(VoucherSaleOutcome::Duplicate);
            }

            if (! in_array($proforma->status, [InvoiceStatus::DRAFT, InvoiceStatus::SENT], true)) {
                return VoucherSaleResult::refused(VoucherSaleOutcome::ProformaNotInForce);
            }

            if ($request->currency !== $this->proformaCurrency($proforma)) {
                return VoucherSaleResult::refused(VoucherSaleOutcome::CurrencyMismatch);
            }

            if ($request->amount->toScale(2)->compareTo($proforma->total_amount->toScale(2)) !== 0) {
                return VoucherSaleResult::refused(VoucherSaleOutcome::AmountMismatch);
            }

            // S1: recorded, not spendable. A key already spent by another payload
            // surfaces here as IdempotencyConflictException and rolls everything back.
            $payment = $this->payments->process(ReceiveRequest::forVoucherSale(
                $request->billableUserId, $request->amount, $request->currency, $request->receivedAt,
                $request->method, $request->reference, $request->recordedBy, $request->operationKey,
            ), ReplayPolicy::Full);

            $claim = new VoucherSaleClaim([
                'proforma_id'               => $proforma->id,
                'grouped_payment_id'        => $payment->id,
                'operation_key'             => $request->operationKey,
                'stage'                     => VoucherSaleStage::Pending,
                'expected_amount'           => $request->amount->toScale(2),
                'expected_currency'         => $request->currency,
                'expected_billable_user_id' => $request->billableUserId,
            ]);
            self::persistOrFail($claim->save(), 'the sale claim');

            return $claim;
        });
    }

    /**
     * Every package write of the sale is checked: Eloquent's `save()` returns
     * false without throwing when a `saving` listener vetoes it, and
     * `create()`/`update()` ignore that return (gate round 1 B1). A vetoed
     * write throws here, inside the stage's transaction, so nothing partial
     * commits — a payment without its claim, a stage without its invoice, an
     * operation without its exits.
     *
     * @throws LogicException
     */
    private static function persistOrFail(?bool $saved, string $what): void
    {
        CheckedWrite::orFail($saved, "voucher sale: {$what}");
    }

    /** S2 — convert the claimed proforma; the PREPARED invoice gets `sale_claim_id` in the same transaction. */
    private function convert(VoucherSaleClaim $claim): Invoice
    {
        $existing = Invoice::query()->where('sale_claim_id', $claim->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($claim): Invoice {
            // Lock order payment → proforma (the global order): a concurrent
            // reverse() at PENDING holds the payment lock while it releases the
            // claim, so S2 serializes behind it and re-reads the claim —
            // measured on MySQL 8.4: without this lock S2 converted while the
            // claim was being deleted and died on the sale_claim_id FK.
            $payment = GroupedPayment::query()->whereKey($claim->grouped_payment_id)->lockForUpdate()->firstOrFail();
            if ($payment->isReversed()) {
                throw IdempotencyConflictException::keySpentByReversal($claim->operation_key);
            }
            $claim = VoucherSaleClaim::query()->whereKey($claim->getKey())->first()
                ?? throw SaleIntegrityException::because(SaleIntegrityReason::ClaimMissing, 'unknown', null, 'the sale claim was released before conversion');

            // Rule 4 on the payment BEFORE any write of S2: a payment that
            // drifted after S1 is refused here with zero writes.
            $this->assertPaymentMatchesClaim($claim, $payment, null);

            $proforma = Invoice::query()->whereKey($claim->proforma_id)->lockForUpdate()->firstOrFail();

            $invoice = VoucherSaleClaim::withSaleAuthorization(
                (string) $claim->id,
                fn (): Invoice => $this->invoices->convertProformaToInvoice($proforma)->invoice,
            );

            Invoice::query()->whereKey($invoice->getKey())->update(['sale_claim_id' => $claim->id]);

            return $invoice->fresh() ?? $invoice;
        });
    }

    /** S3a — revalidate, stage → ISSUING, then issue() under the sale authorization (it writes the mark). */
    private function issueSale(VoucherSaleClaim $claim, Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($claim, $invoice): void {
            [$claim, $payment, $invoice] = $this->lockSale($claim, $invoice);
            $this->revalidate($claim, $payment, $invoice);

            if ($claim->stage === VoucherSaleStage::Pending) {
                $claim->stage      = VoucherSaleStage::Issuing;
                $claim->issuing_at = now();
                self::persistOrFail($claim->save(), 'the ISSUING stage');
            }
        });

        $invoice = $invoice->fresh() ?? $invoice;

        if ($invoice->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
            return VoucherSaleClaim::withSaleAuthorization((string) $claim->id, fn (): Invoice => $this->invoices->issue($invoice));
        }

        if (! $claim->fresh()?->issued_by_sale) {
            throw SaleIntegrityException::because(SaleIntegrityReason::MarkMissing, (string) $claim->id, (string) $invoice->id, 'the invoice is ISSUED but was not issued by the sale');
        }

        return $invoice;
    }

    /** S3b — settle and back, one transaction, the whole tuple re-read before commit. */
    private function back(VoucherSaleClaim $claim, Invoice $invoice): VoucherSaleResult
    {
        // No sale authorization here on purpose: S3b touches collection state,
        // allocations, the entry and the claim — none of them a guarded write.
        return DB::transaction(function () use ($claim, $invoice): VoucherSaleResult {
            [$claim, $payment, $invoice] = $this->lockSale($claim, $invoice);
            $this->revalidate($claim, $payment, $invoice);

            if ($invoice->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
                throw SaleIntegrityException::because(SaleIntegrityReason::DocumentAxisIncoherent, (string) $claim->id, (string) $invoice->id, 'the sale invoice is not ISSUED');
            }

            if (! $claim->issued_by_sale) {
                throw SaleIntegrityException::because(SaleIntegrityReason::MarkMissing, (string) $claim->id, (string) $invoice->id, 'the invoice is ISSUED but was not issued by the sale');
            }

            // A BACKED claim is a replay: it proves the persisted tuple (the
            // same predicates and reasons as diagnose()) and is NEVER rebuilt —
            // a BACKED sale that lost a piece is an incoherence to refuse.
            if ($claim->stage === VoucherSaleStage::Backed) {
                $this->assertTuple($claim, $payment, $invoice);
                $entry = CreditMovement::query()->where('sale_invoice_id', $invoice->id)->active()->entries()->sole();

                return new VoucherSaleResult(VoucherSaleOutcome::Sold, $invoice, $payment, $entry, $claim);
            }
            // Not BACKED: revalidate() has just proven no allocation, no entry, DRAFT.

            // 1. DRAFT → PENDING: recomputeCollectionState() returns early on DRAFT.
            $invoice->status = InvoiceStatus::PENDING;
            self::persistOrFail($invoice->save(), 'the PENDING collection status');

            // 2. ONE allocation of the full payment, 3. the single writer of the transition.
            Ledger::createAllocation((string) $payment->id, $invoice, $claim->expected_amount, $payment->paid_at);
            $invoice->recomputeCollectionState();

            // 4. ONE voucher entry, linked to the payment and the sale invoice.
            $entry = new CreditMovement;
            $entry->forceFill([
                'billable_user_id'   => $payment->billable_user_id,
                'currency'           => $payment->currency,
                'kind'               => CreditMovementKind::TOP_UP,
                'amount'             => $claim->expected_amount,
                'grouped_payment_id' => $payment->id,
                'sale_invoice_id'    => $invoice->id,
                'recorded_by'        => $payment->recorded_by,
                'occurred_at'        => $payment->paid_at,
            ]);
            self::persistOrFail($entry->save(), 'the voucher entry');

            $claim->stage     = VoucherSaleStage::Backed;
            $claim->backed_at = now();
            self::persistOrFail($claim->save(), 'the BACKED stage');

            // Rule 3: the WHOLE persisted tuple, re-read, or nothing.
            $this->assertTuple($claim, $payment, $invoice);

            return new VoucherSaleResult(VoucherSaleOutcome::Sold, $invoice->fresh() ?? $invoice, $payment, $entry->fresh() ?? $entry, $claim->fresh() ?? $claim);
        });
    }

    /**
     * Lock payment → proforma → invoice (the global order) and return the
     * fresh instances.
     *
     * @return array{0: VoucherSaleClaim, 1: GroupedPayment, 2: Invoice}
     */
    private function lockSale(VoucherSaleClaim $claim, Invoice $invoice): array
    {
        $payment = GroupedPayment::query()->whereKey($claim->grouped_payment_id)->lockForUpdate()->firstOrFail();
        Invoice::query()->whereKey($claim->proforma_id)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
        $claim   = VoucherSaleClaim::query()->whereKey($claim->getKey())->first();

        if ($claim === null) {
            throw SaleIntegrityException::because(SaleIntegrityReason::ClaimMissing, 'unknown', (string) $invoice->id, 'the sale claim no longer exists');
        }

        return [$claim, $payment, $invoice];
    }

    /** §1a rule 4 — the invoice and the payment against the frozen expectation. */
    private function revalidate(VoucherSaleClaim $claim, GroupedPayment $payment, Invoice $invoice): void
    {
        $id = (string) $claim->id;

        if (! $payment->isPosted() || $payment->reversed_at !== null) {
            throw SaleIntegrityException::because(SaleIntegrityReason::PaymentNotPosted, $id, (string) $invoice->id, 'the funding payment is not POSTED (or carries a reversal instant)');
        }

        if ($payment->amount->compareTo($claim->expected_amount) !== 0 || $invoice->total_amount->compareTo($claim->expected_amount) !== 0) {
            throw SaleIntegrityException::because(SaleIntegrityReason::AmountDrift, $id, (string) $invoice->id, "expected {$claim->expected_amount->unscaledValue()}, payment {$payment->amount->unscaledValue()}, invoice {$invoice->total_amount->unscaledValue()} (base-100)");
        }

        $invoiceCurrency = self::invoiceCurrency($invoice);
        if ($payment->currency !== $claim->expected_currency || ($invoiceCurrency !== null && $invoiceCurrency !== $claim->expected_currency)) {
            throw SaleIntegrityException::because(SaleIntegrityReason::CurrencyDrift, $id, (string) $invoice->id, "expected {$claim->expected_currency}, payment {$payment->currency}, invoice ".($invoiceCurrency ?? 'none'));
        }

        if ((string) $payment->billable_user_id !== $claim->expected_billable_user_id || (string) $invoice->billable_user_id !== $claim->expected_billable_user_id) {
            throw SaleIntegrityException::because(SaleIntegrityReason::PayerDrift, $id, (string) $invoice->id, 'the payer of the payment or the invoice is not the expected billable user');
        }

        if ((string) $invoice->sale_claim_id !== $id) {
            throw SaleIntegrityException::because(SaleIntegrityReason::ClaimMissing, $id, (string) $invoice->id, 'the invoice does not reference this claim');
        }

        // The document axis BEFORE any stage advances (gate round 1 B6): a
        // CANCELLED sale invoice, or an ISSUED one while the claim is still
        // PENDING, is an incoherence to refuse, never a state to move past.
        $doc = $invoice->invoice_document_status;
        if ($doc === InvoiceDocumentStatus::CANCELLED || ($doc !== InvoiceDocumentStatus::PREPARED && $doc !== InvoiceDocumentStatus::ISSUED)) {
            throw SaleIntegrityException::because(SaleIntegrityReason::DocumentAxisIncoherent, $id, (string) $invoice->id, 'the sale invoice document status is '.($doc instanceof InvoiceDocumentStatus ? $doc->name : 'NULL'));
        }
        if ($doc === InvoiceDocumentStatus::ISSUED && $claim->stage === VoucherSaleStage::Pending) {
            throw SaleIntegrityException::because(SaleIntegrityReason::DocumentAxisIncoherent, $id, (string) $invoice->id, 'the sale invoice is ISSUED while the claim is still PENDING');
        }
        // A BACKED claim's document is ISSUED, full stop: a PREPARED shape
        // under a BACKED claim is an external change, never a sale to resume
        // (gate round 3 — otherwise issue() would mint a second number).
        if ($claim->stage === VoucherSaleStage::Backed && $doc !== InvoiceDocumentStatus::ISSUED) {
            throw SaleIntegrityException::because(SaleIntegrityReason::DocumentAxisIncoherent, $id, (string) $invoice->id, 'the claim is BACKED but the sale invoice is not ISSUED');
        }

        // The sale mark, in the same position diagnose() checks it.
        if ($doc === InvoiceDocumentStatus::ISSUED && ! $claim->issued_by_sale) {
            throw SaleIntegrityException::because(SaleIntegrityReason::MarkMissing, $id, (string) $invoice->id, 'the invoice is ISSUED but was not issued by the sale');
        }

        // Before BACKED nothing may be collected or backed yet — the same
        // predicates diagnose() applies, checked here BEFORE any stage
        // advance or fiscal call (gate round 2 B4). At BACKED the tuple is
        // proven by assertTuple() instead.
        if ($claim->stage !== VoucherSaleStage::Backed) {
            if (PaymentAllocation::query()->where('invoice_id', $invoice->id)->where('status', AllocationStatus::ACTIVE->value)->exists()) {
                throw SaleIntegrityException::because(SaleIntegrityReason::CompetingAllocation, $id, (string) $invoice->id, 'the sale invoice already carries an allocation while the claim is not backed');
            }

            if ($invoice->status !== InvoiceStatus::DRAFT || $invoice->paid_at !== null || CreditMovement::query()->where('sale_invoice_id', $invoice->id)->active()->entries()->exists()) {
                throw SaleIntegrityException::because(SaleIntegrityReason::CollectionStateUnexpected, $id, (string) $invoice->id, "collection status {$invoice->status->name}, a paid_at or a voucher entry before settlement");
            }
        }
    }

    /**
     * The persisted exits of a consumption against the request: every exit
     * CONSUMED, for the request's payer and currency, consuming one of the
     * selected voucher entries, and their amounts summing to exactly the
     * requested amount.
     *
     * @param  array<int, string>  $entryIds
     *
     * @throws SaleIntegrityException
     */
    private function assertConsumptionPersisted(CreditOperation $operation, VoucherConsumptionRequest $request, array $entryIds): void
    {
        $persisted = CreditMovement::query()->where('credit_operation_id', $operation->id)->get();
        $taken     = 0;

        foreach ($persisted as $exit) {
            if ($exit->kind                         !== CreditMovementKind::CONSUMED
                || (string) $exit->billable_user_id !== $request->billableUserId
                || $exit->currency                  !== $request->currency
                || ! in_array((string) $exit->consumes_movement_id, $entryIds, true)
                || ! $exit->amount->isNegative()) {
                throw SaleIntegrityException::because(SaleIntegrityReason::ConsumptionIncoherent, 'consumption:'.$request->operationKey, null, "exit {$exit->id} is not the exit this consumption declared");
            }
            $taken -= $exit->amount->toScale(2)->unscaledValue();
        }

        if ($taken !== $request->amount->toScale(2)->unscaledValue()) {
            throw SaleIntegrityException::because(SaleIntegrityReason::ConsumptionIncoherent, 'consumption:'.$request->operationKey, null, "persisted exits take {$taken} base-100, the consumption declared {$request->amount->toScale(2)->unscaledValue()}");
        }
    }

    /**
     * The single predicate assertTuple() and diagnose() share for the voucher
     * entry: exactly one, from the claimed payment, for the expected amount,
     * in the expected currency, for the expected payer (gate round 7: the
     * entry's own payer and currency are what consumption selects by, so a
     * listener rewriting them would credit another customer or currency).
     *
     * @param  Collection<int, CreditMovement>  $entries
     */
    private static function entryMatches(Collection $entries, VoucherSaleClaim $claim, GroupedPayment $payment): bool
    {
        if ($entries->count() !== 1) {
            return false;
        }

        $entry = $entries->sole();

        return (string) $entry->grouped_payment_id                === (string) $payment->id
            && $entry->amount->compareTo($claim->expected_amount) === 0
            && $entry->currency                                   === $claim->expected_currency
            && (string) $entry->billable_user_id                  === $claim->expected_billable_user_id
            && $entry->kind                                       === CreditMovementKind::TOP_UP;
    }

    /**
     * The currency the sale invoice is denominated in: the FROZEN fiscal
     * snapshot once it exists (what issue() stamped — a later change of the
     * referenced config does not re-denominate an issued document, gate
     * round 4), the referenced config before that, null when it has none.
     */
    private static function invoiceCurrency(Invoice $invoice): ?string
    {
        // ISSUED: the snapshot issue() stamped is the document's currency.
        // PREPARED: issue() will re-stamp the snapshot from the referenced
        // config, so the config IS the currency the document will carry.
        if ($invoice->invoice_document_status === InvoiceDocumentStatus::ISSUED) {
            $frozen = $invoice->getFiscalSnapshotData()['currency'] ?? null;
            if (is_string($frozen) && $frozen !== '') {
                return $frozen;
            }
        }

        return $invoice->companyFiscalConfig()->first()?->currency;
    }

    /**
     * The funding payment against the frozen expectation — the half of rule
     * 4 that needs no invoice, so S2 can refuse a payment that drifted after
     * S1 BEFORE converting anything (gate round 4: the re-call must write
     * nothing when it refuses).
     */
    private function assertPaymentMatchesClaim(VoucherSaleClaim $claim, GroupedPayment $payment, ?string $invoiceId): void
    {
        $claim->assertPaymentMatches($payment, $invoiceId); // the claim owns the expectation; issue() uses the same check
    }

    /** §1a rule 3 — exactly one active allocation from the payment for the amount, PAID with the payment's paid_at, exactly one voucher entry, stage BACKED. */
    private function assertTuple(VoucherSaleClaim $claim, GroupedPayment $payment, Invoice $invoice): void
    {
        $id = (string) $claim->id;

        // The funding payment RE-READ after the writes (gate round 8): a
        // listener on the entry save could have rewritten it; the in-memory
        // instance proves nothing about the row as it is now.
        $payment = GroupedPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
        $claim->assertPaymentMatches($payment, (string) $invoice->id);

        $fresh   = Invoice::query()->whereKey($invoice->getKey())->firstOrFail();
        $rows    = PaymentAllocation::query()->where('invoice_id', $invoice->id)->where('status', AllocationStatus::ACTIVE->value)->get();
        $entries = CreditMovement::query()->where('sale_invoice_id', $invoice->id)->active()->entries()->get();
        $stage   = VoucherSaleClaim::query()->whereKey($claim->getKey())->firstOrFail()->stage;

        if ($rows->count() !== 1 || (string) $rows->sole()->grouped_payment_id !== (string) $payment->id || $rows->sole()->applied_amount->compareTo($claim->expected_amount) !== 0) {
            throw SaleIntegrityException::because(SaleIntegrityReason::CompetingAllocation, $id, (string) $invoice->id, 'the sale invoice must carry exactly one active allocation from the claimed payment for the expected amount');
        }

        if ($fresh->status !== InvoiceStatus::PAID || $fresh->paid_at === null || ! $fresh->paid_at->equalTo($payment->paid_at)) {
            throw SaleIntegrityException::because(SaleIntegrityReason::CollectionStateUnexpected, $id, (string) $invoice->id, 'the sale invoice is not PAID with the payment\'s paid_at after settlement');
        }

        if (! self::entryMatches($entries, $claim, $payment)) {
            throw SaleIntegrityException::because(SaleIntegrityReason::CollectionStateUnexpected, $id, (string) $invoice->id, 'the sale must carry exactly one voucher entry from the claimed payment, for the expected amount, in the expected currency and for the expected payer');
        }

        if ($stage !== VoucherSaleStage::Backed) {
            throw SaleIntegrityException::because(SaleIntegrityReason::CollectionStateUnexpected, $id, (string) $invoice->id, 'the claim is not BACKED after settlement');
        }
    }

    /**
     * Consume voucher balance against a service (§1 item 5): CONSUMED exits
     * FIFO across the customer's voucher-backed entries in $currency, all or
     * nothing — an amount above the voucher balance refuses the whole
     * consumption with zero writes — idempotent by the consumer's operation
     * key (same payload → the original exits; another payload →
     * IdempotencyConflictException). Consumable and advance-backed balance is
     * never touched here. Returns one receipt per exit; nothing fiscal is
     * emitted. Locks: the source payments by id ascending, as apply(). Must
     * NOT run inside a caller's transaction.
     *
     * @throws PaymentOutsideTransactionRequiredException
     * @throws GroupedPaymentValidationException on a non-positive or sub-cent amount
     * @throws InsufficientCreditException when the voucher balance cannot cover the amount
     * @throws IdempotencyConflictException when the key names another payload
     */
    public function consumeVoucher(VoucherConsumptionRequest $request): VoucherConsumptionResult
    {
        OutermostTransaction::assert(__METHOD__);

        $amount = $request->amount->toScale(2);
        if (! $amount->isPositive()) {
            throw GroupedPaymentValidationException::nonPositiveAmount($amount->unscaledValue());
        }
        if ($amount->compareTo($request->amount) !== 0) {
            throw GroupedPaymentValidationException::subCentAmount($request->amount->toDecimalString());
        }

        // Plain read OUTSIDE the transaction: the POSTED payments holding an active voucher entry.
        $paymentIds = CreditMovement::query()
            ->where('billable_user_id', $request->billableUserId)
            ->where('currency', $request->currency)
            ->active()->entries()
            ->whereNotNull('sale_invoice_id')
            ->whereIn('grouped_payment_id', GroupedPayment::query()->select('id')->where('status', 0))
            ->distinct()->pluck('grouped_payment_id')->map(fn ($id): string => (string) $id)->sort()->values()->all();

        try {
            return DB::transaction(function () use ($request, $amount, $paymentIds): VoucherConsumptionResult {
                // FIRST statement: the locking read of every source payment.
                if ($paymentIds !== []) {
                    GroupedPayment::query()->whereIn('id', $paymentIds)->orderBy('id')->lockForUpdate()->get();
                }

                $existing = CreditOperation::query()->where('operation_key', $request->operationKey)->first();
                if ($existing !== null) {
                    return $this->replayConsumption($existing, $request);
                }

                $entries = $paymentIds === [] ? collect() : CreditMovement::query()
                    ->where('billable_user_id', $request->billableUserId)
                    ->where('currency', $request->currency)
                    ->active()->entries()
                    ->whereNotNull('sale_invoice_id')
                    ->whereIn('grouped_payment_id', $paymentIds)
                    ->orderBy('occurred_at')->orderBy('id')
                    ->get();

                $remaining = [];
                $balance   = FixedDecimal::zero(2);
                foreach ($entries as $entry) {
                    $remaining[(string) $entry->id] = $entry->remaining();
                    $balance                        = $balance->plus($remaining[(string) $entry->id]);
                }

                if ($amount->isGreaterThan($balance)) {
                    throw InsufficientCreditException::forBalance($request->billableUserId, $request->currency, $balance->toScale(2)->unscaledValue(), $amount->unscaledValue());
                }

                $operation = new CreditOperation([
                    'operation_key'    => $request->operationKey,
                    'kind'             => CreditOperationKind::Consumption,
                    'billable_user_id' => $request->billableUserId,
                    'fingerprint'      => $request->fingerprint(),
                    'recorded_at'      => now(),
                ]);
                self::persistOrFail($operation->save(), 'the consumption operation');

                /** @var Collection<int, CreditMovement> $exits */
                $exits = new Collection;
                $left  = $amount;
                foreach ($entries as $entry) {
                    if (! $left->isPositive()) {
                        break;
                    }
                    $available = $remaining[(string) $entry->id];
                    if (! $available->isPositive()) {
                        continue;
                    }
                    $take = $available->isLessThan($left) ? $available : $left;

                    // Same band placement as apply()/allocate() (AID-1778): one row
                    // per filled gap; an unresolvable entry gets one NULL-band row.
                    $takeCents = $take->toScale(2)->unscaledValue();
                    $segments  = ExitBands::segmentsFor($entry, $takeCents) ?? [[null, $takeCents]];
                    foreach ($segments as [$start, $length]) {
                        $exits->push($this->consumedExit($entry, $operation, $request, $start, $length));
                    }

                    $left = $left->minus($take);
                }

                // The PERSISTED exits, re-read, or nothing (gate round 8): a
                // listener could have rewritten an exit before its insert; the
                // operation commits only if what is on disk IS the consumption.
                $this->assertConsumptionPersisted($operation, $request, $entries->pluck('id')->map(fn ($v): string => (string) $v)->values()->all());

                return new VoucherConsumptionResult($exits, $this->receipts($exits, $request), false);
            });
        } catch (QueryException $e) {
            // Another transaction won the unique operation_key race: replay it if the payload matches.
            if ($e->getCode() !== '23000' || ! str_contains($e->getMessage(), 'operation_key')) {
                throw $e;
            }
            $raced = CreditOperation::query()->where('operation_key', $request->operationKey)->first();
            if ($raced === null) {
                throw $e;
            }

            return $this->replayConsumption($raced, $request);
        }
    }

    /**
     * Cancel a top-up proforma (for example on expiry) under its row lock
     * (§1 item 6): a DRAFT or SENT proforma that no sale has claimed moves to
     * CANCELLED; a claimed, converting, converted or already cancelled one is
     * refused. A closed proforma is never reopened; continuation is a new
     * proforma.
     *
     * @throws InvoiceNotCancellableException when the document is not a proforma or is not in force
     * @throws SaleClaimedException when a voucher sale has claimed the proforma
     */
    public function cancelProforma(Invoice $proforma, string $actor): Invoice
    {
        return DB::transaction(function () use ($proforma, $actor): Invoice {
            $locked = Invoice::query()->whereKey($proforma->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->serie !== InvoiceSerieType::PROFORMA) {
                throw InvoiceNotCancellableException::notAProforma($locked);
            }

            // A closed proforma (converting, converted, cancelled) is not in
            // force whoever asks; an in-force one claimed by a sale belongs
            // to the sale until reverse() at PENDING releases it.
            if (! in_array($locked->status, [InvoiceStatus::DRAFT, InvoiceStatus::SENT], true)) {
                throw InvoiceNotCancellableException::proformaNotInForce($locked);
            }

            $claimId = VoucherSaleClaim::query()->where('proforma_id', $locked->getKey())->value('id');
            if ($claimId !== null) {
                throw SaleClaimedException::proforma((string) $locked->getKey(), (string) $claimId, 'cancelProforma() by '.$actor);
            }

            $locked->status = InvoiceStatus::CANCELLED;
            self::persistOrFail($locked->save(), 'the proforma cancellation');

            return $locked->fresh() ?? $locked;
        });
    }

    /** One CONSUMED exit row of $lengthCents on $entry, placed at $bandStart (NULL on an unresolvable entry). */
    private function consumedExit(CreditMovement $entry, CreditOperation $operation, VoucherConsumptionRequest $request, ?int $bandStart, int $lengthCents): CreditMovement
    {
        $exit = new CreditMovement;
        $exit->forceFill([
            'billable_user_id'     => $entry->billable_user_id,
            'currency'             => $entry->currency,
            'kind'                 => CreditMovementKind::CONSUMED,
            'amount'               => FixedDecimal::ofUnscaled(-$lengthCents, 2),
            'grouped_payment_id'   => $entry->grouped_payment_id,
            'consumes_movement_id' => $entry->id,
            'band_start'           => $bandStart,
            'credit_operation_id'  => $operation->id,
            'service_ref'          => $request->serviceRef,
            'service_description'  => $request->serviceDescription,
            'recorded_by'          => $request->recordedBy,
            'occurred_at'          => $request->consumedAt,
        ]);
        self::persistOrFail($exit->save(), 'a CONSUMED exit');

        return $exit;
    }

    private function replayConsumption(CreditOperation $operation, VoucherConsumptionRequest $request): VoucherConsumptionResult
    {
        if ($operation->fingerprint !== $request->fingerprint() || (string) $operation->billable_user_id !== $request->billableUserId) {
            throw IdempotencyConflictException::forKey($request->operationKey);
        }

        /** @var Collection<int, CreditMovement> $exits */
        $exits = CreditMovement::query()->where('credit_operation_id', $operation->id)->orderBy('occurred_at')->orderBy('id')->get();

        return new VoucherConsumptionResult($exits, $this->receipts($exits, $request), true);
    }

    /**
     * @param  Collection<int, CreditMovement>  $exits
     * @return list<VoucherReceiptData>
     */
    private function receipts(Collection $exits, VoucherConsumptionRequest $request): array
    {
        $voucherBalance = CustomerCredit::for($request->billableUserId, $request->currency)->breakdown()->voucherBacked;

        $receipts = [];
        foreach ($exits as $exit) {
            $entry = CreditMovement::query()->whereKey($exit->consumes_movement_id)->firstOrFail();
            $sale  = Invoice::query()->whereKey($entry->sale_invoice_id)->first();

            $receipts[] = new VoucherReceiptData(
                (string) $exit->id,
                (string) $entry->sale_invoice_id,
                $sale?->fiscal_number,
                $sale?->invoice_date,
                $exit->amount->negated()->toScale(2),
                $entry->remaining()->toScale(2),
                $voucherBalance,
                (string) $exit->service_ref,
                $exit->service_description,
                $exit->occurred_at,
            );
        }

        return $receipts;
    }

    /**
     * The normal crash window (§3): sale invoices ISSUED and marked by the
     * sale whose claim is ISSUING and has no voucher entry yet — completed by
     * the same-key re-call when every check passes. Plain reads, no lock.
     *
     * @return SupportCollection<int, SaleRecoveryRow>
     */
    public function salesAwaitingBalance(): SupportCollection
    {
        return $this->saleInvoices()
            ->toBase()
            ->map(fn (Invoice $invoice): ?SaleRecoveryRow => $this->diagnose($invoice) === null && $this->awaitsBalance($invoice) ? $this->row($invoice, null) : null)
            ->filter()
            ->values();
    }

    /**
     * Every sale invoice whose claim, payment and rows disagree (§3), each
     * with the first reason found — the same reason the re-call throws.
     * Starts from the INVOICES carrying `sale_claim_id`, so a lost claim is
     * listed too. Plain reads, no lock.
     *
     * @return SupportCollection<int, SaleRecoveryRow>
     */
    public function salesIncoherent(): SupportCollection
    {
        return $this->saleInvoices()
            ->toBase()
            ->map(function (Invoice $invoice): ?SaleRecoveryRow {
                $reason = $this->diagnose($invoice);

                return $reason === null ? null : $this->row($invoice, $reason);
            })
            ->filter()
            ->values();
    }

    /** @return Collection<int, Invoice> */
    private function saleInvoices(): Collection
    {
        return Invoice::query()->whereNotNull('sale_claim_id')->orderBy('id')->get();
    }

    private function awaitsBalance(Invoice $invoice): bool
    {
        $claim = VoucherSaleClaim::query()->whereKey($invoice->getAttribute('sale_claim_id'))->first();

        return $claim !== null
            && $claim->stage                     === VoucherSaleStage::Issuing
            && $invoice->invoice_document_status === InvoiceDocumentStatus::ISSUED
            && $claim->issued_by_sale
            && ! CreditMovement::query()->where('sale_invoice_id', $invoice->id)->active()->exists();
    }

    /**
     * The first reason a sale invoice is incoherent, or null when its claim,
     * payment and rows agree with what its stage implies (§3 predicates).
     */
    private function diagnose(Invoice $invoice): ?SaleIntegrityReason
    {
        $claim = VoucherSaleClaim::query()->whereKey($invoice->getAttribute('sale_claim_id'))->first();
        if ($claim === null) {
            return SaleIntegrityReason::ClaimMissing;
        }

        $payment = GroupedPayment::query()->whereKey($claim->grouped_payment_id)->first();
        if ($payment === null || ! $payment->isPosted() || $payment->reversed_at !== null) {
            return SaleIntegrityReason::PaymentNotPosted;
        }

        if ($invoice->total_amount->compareTo($claim->expected_amount) !== 0 || $payment->amount->compareTo($claim->expected_amount) !== 0) {
            return SaleIntegrityReason::AmountDrift;
        }

        // The same currency predicate as revalidate(): the payment AND the invoice.
        $invoiceCurrency = self::invoiceCurrency($invoice);
        if ($payment->currency !== $claim->expected_currency || ($invoiceCurrency !== null && $invoiceCurrency !== $claim->expected_currency)) {
            return SaleIntegrityReason::CurrencyDrift;
        }

        if ((string) $invoice->billable_user_id !== $claim->expected_billable_user_id || (string) $payment->billable_user_id !== $claim->expected_billable_user_id) {
            return SaleIntegrityReason::PayerDrift;
        }

        // The same document-axis predicate as revalidate(): CANCELLED or an
        // unexpected status is incoherent at any stage; ISSUED while the claim
        // is still PENDING is incoherent; PREPARED at ISSUING is the normal
        // crash window.
        $doc = $invoice->invoice_document_status;
        if ($doc === InvoiceDocumentStatus::CANCELLED || ($doc !== InvoiceDocumentStatus::PREPARED && $doc !== InvoiceDocumentStatus::ISSUED)) {
            return SaleIntegrityReason::DocumentAxisIncoherent;
        }
        if ($doc === InvoiceDocumentStatus::ISSUED && $claim->stage === VoucherSaleStage::Pending) {
            return SaleIntegrityReason::DocumentAxisIncoherent;
        }

        if ($doc === InvoiceDocumentStatus::ISSUED && ! $claim->issued_by_sale) {
            return SaleIntegrityReason::MarkMissing;
        }

        $allocations = PaymentAllocation::query()->where('invoice_id', $invoice->id)->where('status', AllocationStatus::ACTIVE->value)->get();
        $entries     = CreditMovement::query()->where('sale_invoice_id', $invoice->id)->active()->entries()->get();

        if ($claim->stage === VoucherSaleStage::Backed) {
            if ($doc !== InvoiceDocumentStatus::ISSUED) {
                return SaleIntegrityReason::DocumentAxisIncoherent;
            }

            // The same tuple predicates as assertTuple(): one allocation from
            // the claimed payment for the amount; PAID with the payment's
            // paid_at; one entry from the claimed payment for the amount.
            if ($allocations->count() !== 1 || (string) $allocations->sole()->grouped_payment_id !== (string) $payment->id || $allocations->sole()->applied_amount->compareTo($claim->expected_amount) !== 0) {
                return SaleIntegrityReason::CompetingAllocation;
            }

            if ($invoice->status !== InvoiceStatus::PAID || $invoice->paid_at === null || ! $invoice->paid_at->equalTo($payment->paid_at)) {
                return SaleIntegrityReason::CollectionStateUnexpected;
            }

            if (! self::entryMatches($entries, $claim, $payment)) {
                return SaleIntegrityReason::CollectionStateUnexpected;
            }

            return null;
        }

        // PENDING or ISSUING: nothing collected yet, nothing backed yet.
        if ($allocations->isNotEmpty()) {
            return SaleIntegrityReason::CompetingAllocation;
        }

        if ($invoice->status !== InvoiceStatus::DRAFT || $invoice->paid_at !== null || $entries->isNotEmpty()) {
            return SaleIntegrityReason::CollectionStateUnexpected;
        }

        return null;
    }

    private function row(Invoice $invoice, ?SaleIntegrityReason $reason): SaleRecoveryRow
    {
        $claim = VoucherSaleClaim::query()->whereKey($invoice->getAttribute('sale_claim_id'))->first();

        return new SaleRecoveryRow(
            (string) $invoice->id,
            $claim?->proforma_id        !== null ? (string) $claim->proforma_id : ($invoice->proforma_id !== null ? (string) $invoice->proforma_id : null),
            $claim?->grouped_payment_id !== null ? (string) $claim->grouped_payment_id : null,
            $claim                      !== null ? (string) $claim->id : (string) $invoice->getAttribute('sale_claim_id'),
            $claim?->stage,
            $reason,
        );
    }

    /** The currency the proforma was priced in: its snapshot config, else the active issuer config. */
    private function proformaCurrency(Invoice $proforma): string
    {
        // A proforma snapshots no fiscal config (serie PROFORMA skips it), so the
        // relation is read as a query: null for most proformas, then the active issuer config.
        $snapshot = $proforma->companyFiscalConfig()->first();
        if ($snapshot !== null) {
            return $snapshot->currency;
        }

        $active = CompanyFiscalConfig::getActive();

        return $active !== null ? $active->currency : 'EUR';
    }
}
