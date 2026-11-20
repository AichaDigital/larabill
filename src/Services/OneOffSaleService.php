<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\DTO\OneOffSaleResult;
use AichaDigital\Larabill\DTO\ProformaConversionResult;
use AichaDigital\Larabill\DTO\SaleRefundResult;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\SaleIntegrityReason;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientRefundableException;
use AichaDigital\Larabill\Exceptions\SaleChargeMismatchException;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use AichaDigital\Larabill\Exceptions\SaleNotReservableException;
use AichaDigital\Larabill\Exceptions\SaleReservationNotFoundException;
use AichaDigital\Larabill\Exceptions\SaleReservedException;
use AichaDigital\Larabill\Exceptions\SaleStageException;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\SaleRefund;
use AichaDigital\Larabill\Models\SaleReservation;
use AichaDigital\Larabill\Services\Payments\Ledger;
use AichaDigital\Larabill\Services\Payments\OutermostTransaction;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\Support\CheckedWrite;
use AichaDigital\Larabill\Support\DocumentCurrency;
use AichaDigital\Larabill\Support\RawDocumentRead;
use AichaDigital\Larabill\ValueObjects\SaleRefundRequest;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one-off sale without vouchers (AID-1997, spec §4, D1-D8 rev11-final).
 *
 * A reservation claims a SENT proforma for a direct sale (C2), settles it
 * through the named C1 transition and a single allocation (C3/C4), and
 * refunds it through a rectifying invoice with NO credit balance (D8 rev11).
 * larabill issues the documents and records the money; the WHEN and WHY of
 * a sale or a refund is consumer policy (products/larabill.md rev3 §
 * «Reversión de ventas y cobros», owner-approved 2026-11-15).
 *
 * Lock protocol (§4.0): payment → proforma → invoice → reservation — the
 * reservation is always locked LAST. Lookup by `operation_key` is a plain
 * read used only to find ids; decisions are taken on the rows re-read under
 * the locks.
 *
 * This file implements §4.1 reserve() and §4.2 release() (C2). The settle
 * stages (§4.3) and the refund (§4.5, D8 rev11) land with their own tests.
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
class OneOffSaleService
{
    public function __construct(
        protected InvoiceService $invoices,
        protected PaymentService $payments,
    ) {}

    /**
     * Reserve a proforma for a one-off sale (spec §4.1, C2) — one
     * transaction: lock the proforma and the live reservations of it, freeze
     * the expectation, write the reservation and the immutable freeze.
     *
     * @param  string  $proformaId  the proforma to reserve
     * @param  string  $operationKey  the sale's idempotency key (≤191 chars)
     *
     * @throws IdempotencyConflictException the key names another proforma's reservation
     * @throws SaleReservedException a live reservation with another key exists
     * @throws SaleNotReservableException the proforma is not reservable (typed reason)
     */
    public function reserve(string $proformaId, string $operationKey): SaleReservation
    {
        if (mb_strlen($operationKey) > 191) {
            throw SaleNotReservableException::forStatus(new SaleReservation(['operation_key' => $operationKey, 'proforma_id' => $proformaId]));
        }

        // Plain read to find the ids (§4.0); decisions below are on locked rows.
        $existing = SaleReservation::query()->where('operation_key', $operationKey)->first();

        return DB::transaction(function () use ($proformaId, $operationKey, $existing): SaleReservation {
            $proforma = Invoice::query()->whereKey($proformaId)->lockForUpdate()->firstOrFail();
            $live     = SaleReservation::query()
                ->where('proforma_id', $proformaId)
                ->where('stage', '!=', SaleReservationStage::Released->value)
                ->lockForUpdate()
                ->get();

            // Step 3: the key's own reservation — replay, conflict, or a
            // spent key (§4.1: a Released reservation never replays and its
            // key never re-enters — spec §7 C2, key reuse after release).
            if ($existing !== null) {
                if ((string) $existing->proforma_id !== $proformaId) {
                    throw IdempotencyConflictException::forKey($operationKey);
                }

                if ($existing->stage === SaleReservationStage::Released) {
                    throw SaleNotReservableException::forStatus($existing);
                }

                return $existing; // replay: the expectation is NOT recomputed
            }

            // Step 4: another key's LIVE reservation of this proforma.
            if (($other = $live->first()) !== null) {
                throw SaleReservedException::forProforma($proformaId, $other->operation_key, $operationKey);
            }

            // Step 5: the typed reservability list.
            $this->assertReservable($proforma);

            // Step 6: the reservation and the freeze, both checked writes,
            // under the reservation's authorization (the proforma guards
            // refuse an unauthorized freeze).
            $reservation = new SaleReservation([
                'proforma_id'               => $proforma->id,
                'operation_key'             => $operationKey,
                'stage'                     => SaleReservationStage::Reserved,
                'expected_amount'           => $proforma->total_amount,
                'expected_currency'         => $this->proformaCurrency($proforma),
                'expected_billable_user_id' => (string) $proforma->billable_user_id,
            ]);
            $reservation->save();

            SaleReservation::withReservationAuthorization((string) $reservation->id, function () use ($proforma): void {
                $proforma->is_immutable = true;
                $proforma->immutable_at = now();
                CheckedWrite::orFail($proforma->save(), 'the proforma freeze');
            });

            // Step 7: re-read the persisted state before commit.
            $stored = SaleReservation::query()->whereKey($reservation->id)->firstOrFail();

            return $stored;
        });
    }

    /**
     * Release an abandoned checkout (spec §4.2, C2) — one transaction: lock
     * proforma → reservation, unfreeze the proforma under the reservation's
     * authorization, move the reservation to Released. The key is spent: a
     * later reserve() with it is refused. Allowed at Reserved only (§8 Q1=A:
     * at Paid or later the sale resumes forward, it does not go back).
     *
     * @throws SaleStageException at any stage past Reserved
     */
    public function release(string $operationKey, string $releasedBy): SaleReservation
    {
        return DB::transaction(function () use ($operationKey, $releasedBy): SaleReservation {
            $reservation = SaleReservation::query()->where('operation_key', $operationKey)->lockForUpdate()->firstOrFail();
            $proforma    = Invoice::query()->whereKey($reservation->proforma_id)->lockForUpdate()->firstOrFail();

            if ($reservation->stage !== SaleReservationStage::Reserved) {
                throw SaleStageException::forRelease($reservation->stage, $operationKey);
            }

            SaleReservation::withReservationAuthorization((string) $reservation->id, function () use ($proforma, $reservation, $releasedBy): void {
                $proforma->is_immutable = false;
                $proforma->immutable_at = null;
                CheckedWrite::orFail($proforma->save(), 'the proforma unfreeze');

                $reservation->stage       = SaleReservationStage::Released;
                $reservation->released_at = now();
                $reservation->released_by = $releasedBy;
                CheckedWrite::orFail($reservation->save(), 'the Released transition');
            });

            $fresh = $reservation->fresh();

            return $fresh ?? $reservation;
        });
    }

    /**
     * Settle a reserved one-off sale (spec §4.3, C3/C4) — four stages, each
     * resumable by the same key, every stage its own transaction with its
     * own lock set (§4.0: payment → proforma → invoice → reservation):
     *
     *   S1 record the charge (no allocations, no remainder entry — the
     *      money belongs to the sale, not to credit);
     *   S2 convert the proforma; the PREPARED invoice gets the
     *      reservation link in the same transaction;
     *   S3 issue under the reservation's authorization (issue() stays its
     *      own outermost transaction);
     *   S4 collect through the named C1 transition and ONE allocation of
     *      the full payment; stage Settled; the whole tuple re-read.
     *
     * A Settled reservation replays the evidence, not today's state (D7):
     * the SAME payment, invoice and allocation are returned.
     *
     * @throws SaleReservationNotFoundException no live reservation for the key
     * @throws SaleChargeMismatchException the charge does not match the frozen expectation (zero writes)
     */
    public function settle(SaleSettlementRequest $request): OneOffSaleResult
    {
        OutermostTransaction::assert(__METHOD__);

        $reservation = $this->findReservation($request->operationKey);

        if ($reservation->stage === SaleReservationStage::Settled) {
            return $this->settledEvidence($reservation); // D7: the evidence, not today's state
        }

        $payment  = $this->recordCharge($reservation, $request);      // S1
        $invoice  = $this->convert($reservation);                     // S2
        $invoice  = $this->issueForSale($reservation, $invoice);      // S3
        $evidence = $this->collect($reservation, $payment, $invoice, $request); // S4

        return $evidence;
    }

    /** The reservation named by the key — live (not Released), or not found. */
    protected function findReservation(string $operationKey): SaleReservation
    {
        $reservation = SaleReservation::query()->where('operation_key', $operationKey)->first();

        if ($reservation === null || $reservation->stage === SaleReservationStage::Released) {
            throw SaleReservationNotFoundException::forKey($operationKey);
        }

        return $reservation;
    }

    /**
     * S1 — record the charge (spec §4.3 S1): compare the payer, currency
     * and amount against the frozen expectation AND the locked proforma
     * (the drift check, D4); then record the payment through
     * PaymentService::process() — no allocations, no remainder entry —
     * under ReplayPolicy::Full; a lost race on the key UNIQUE is a
     * conflict, never an adoption.
     *
     * Resume (AID-2063): at Paid or Issuing the same request re-runs the
     * same checks and the same process() call, which replays the recorded
     * payment without writing (or refuses a different payload under the
     * key); settle() then goes on to S2-S4. Only a Released reservation
     * cannot resume.
     */
    protected function recordCharge(SaleReservation $reservation, SaleSettlementRequest $request): GroupedPayment
    {
        return DB::transaction(function () use ($reservation, $request): GroupedPayment {
            $proforma = Invoice::query()->whereKey($reservation->proforma_id)->lockForUpdate()->firstOrFail();
            $locked   = SaleReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage === SaleReservationStage::Settled) {
                return $locked->payment()->firstOrFail(); // a concurrent settle finished S1-S4
            }

            if ($locked->stage === SaleReservationStage::Released) {
                throw SaleReservationNotFoundException::forKey($request->operationKey); // released concurrently
            }

            // The frozen expectation (D3/D4): payer, currency, amount —
            // and the same three against the locked proforma (drift).
            if ($request->billableUserId !== $locked->expected_billable_user_id) {
                throw SaleChargeMismatchException::payer($locked, $request->billableUserId, $locked->expected_billable_user_id);
            }

            if ($request->currency !== $locked->expected_currency) {
                throw SaleChargeMismatchException::currency($locked, $request->currency, $locked->expected_currency);
            }

            if ($request->amount->toScale(2)->compareTo($locked->expected_amount->toScale(2)) !== 0) {
                throw SaleChargeMismatchException::amount($locked, $request->amount->toScale(2)->unscaledValue(), $locked->expected_amount->toScale(2)->unscaledValue());
            }

            if ($request->amount->toScale(2)->compareTo($proforma->total_amount->toScale(2)) !== 0
                || $request->billableUserId                                                  !== (string) $proforma->billable_user_id) {
                throw SaleChargeMismatchException::proformaDrift($locked, 'the locked proforma no longer matches the charge');
            }

            $payment = $this->payments->process(
                ReceiveRequest::forOneOffSale(
                    $request->billableUserId,
                    $request->amount,
                    $request->currency,
                    $request->receivedAt,
                    $request->method,
                    $request->reference,
                    $request->recordedBy,
                    $request->operationKey,
                ),
                ReplayPolicy::Full,
            );

            if ($locked->grouped_payment_id !== null) {
                // Replay of S1 (resume at Paid/Issuing): process() matched the
                // recorded payment under the key and wrote nothing.
                if ((string) $locked->grouped_payment_id !== (string) $payment->id) {
                    throw SaleIntegrityException::because(
                        SaleIntegrityReason::ReservationStageUnexpected,
                        (string) $locked->id,
                        (string) $proforma->id,
                        'the key names a payment other than the sale\'s recorded one at S1'
                    );
                }

                return $payment;
            }

            $locked->grouped_payment_id = (string) $payment->id;
            $locked->stage              = SaleReservationStage::Paid;
            $locked->paid_at            = now();
            CheckedWrite::orFail($locked->save(), 'the Paid transition');

            return $payment;
        });
    }

    /**
     * S2 — convert the reserved proforma (spec §4.3 S2): under the
     * reservation's authorization, convert; the PREPARED invoice gets
     * `sale_reservation_id` in the same transaction. An existing conversion
     * is adopted only if it carries this reservation's link.
     */
    protected function convert(SaleReservation $reservation): Invoice
    {
        return DB::transaction(function () use ($reservation): Invoice {
            $proforma = Invoice::query()->whereKey($reservation->proforma_id)->lockForUpdate()->firstOrFail();
            $locked   = SaleReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $payment  = GroupedPayment::query()->whereKey($locked->grouped_payment_id)->lockForUpdate()->firstOrFail();

            // Re-check the payment against the reservation (POSTED, unchanged).
            if ($payment->status !== GroupedPaymentStatus::POSTED) {
                throw SaleIntegrityException::because(
                    SaleIntegrityReason::PaymentReversed,
                    (string) $locked->id,
                    (string) $proforma->id,
                    'the funding payment is not POSTED at S2'
                );
            }

            $existing = Invoice::query()->where('sale_reservation_id', $locked->id)->first();

            if ($existing !== null) {
                return $existing; // replay of S2: adopted — it carries this reservation's link
            }

            $result = SaleReservation::withReservationAuthorization((string) $locked->id, function () use ($proforma): ProformaConversionResult {
                return $this->invoices->convertProformaToInvoice($proforma);
            });

            $prepared                      = $result->invoice;
            $prepared->sale_reservation_id = (string) $locked->id;
            CheckedWrite::orFail($prepared->save(), 'the sale link on the PREPARED invoice');

            return $prepared->fresh() ?? $prepared;
        });
    }

    /**
     * S3 — issue under the reservation's authorization (spec §4.3 S3).
     * `issue()` runs as its OWN outermost transaction, never inside a
     * stage wrapper (spec §3: nested, a deadlock escapes instead of being
     * retried by issue()).
     */
    protected function issueForSale(SaleReservation $reservation, Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($reservation): void {
            $locked = SaleReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage === SaleReservationStage::Reserved) {
                throw SaleStageException::forRelease($locked->stage, $reservation->operation_key);
            }

            if ($locked->stage === SaleReservationStage::Paid) {
                $locked->stage       = SaleReservationStage::Issuing;
                $locked->issuing_at  = now();
                CheckedWrite::orFail($locked->save(), 'the Issuing transition');
            }
        });

        $invoice = $invoice->fresh() ?? $invoice;

        if ($invoice->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
            return SaleReservation::withReservationAuthorization((string) $reservation->id, fn (): Invoice => $this->invoices->issue($invoice));
        }

        return $invoice;
    }

    /**
     * S4 — collect (spec §4.3 S4): the named C1 transition, ONE allocation
     * of the full payment, the collection recomputed, stage Settled, and
     * the whole tuple re-read before commit.
     */
    protected function collect(SaleReservation $reservation, GroupedPayment $payment, Invoice $invoice, SaleSettlementRequest $request): OneOffSaleResult
    {
        return DB::transaction(function () use ($reservation, $payment, $invoice): OneOffSaleResult {
            $locked   = SaleReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $payment  = GroupedPayment::query()->whereKey($locked->grouped_payment_id ?? $payment->id)->lockForUpdate()->firstOrFail();
            $invoice  = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage === SaleReservationStage::Settled) {
                return $this->settledEvidence($locked); // a concurrent settle won
            }

            if ($invoice->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
                throw SaleIntegrityException::because(
                    SaleIntegrityReason::InvoiceNotIssued,
                    (string) $locked->id,
                    (string) $invoice->id,
                    'the sale invoice is not ISSUED at S4'
                );
            }

            // AID-2063 (gate round 1, P1-2): the funding is re-checked under
            // S4's own locks — a payment reversed (or altered) after S2 must
            // not be allocated to the sale invoice.
            if ($payment->status !== GroupedPaymentStatus::POSTED) {
                throw SaleIntegrityException::because(
                    SaleIntegrityReason::PaymentReversed,
                    (string) $locked->id,
                    (string) $invoice->id,
                    'the funding payment is not POSTED at S4'
                );
            }

            $documentCurrency = DocumentCurrency::of($invoice);
            if ($documentCurrency !== null && $documentCurrency !== $payment->currency) {
                throw SaleIntegrityException::because(
                    SaleIntegrityReason::CurrencyDrift,
                    (string) $locked->id,
                    (string) $invoice->id,
                    "the payment currency ({$payment->currency}) differs from the issued document's {$documentCurrency} at S4"
                );
            }

            // The C1 transition under the reservation's authorization:
            // G13 refuses every other caller (spec section 4.3 S4).
            SaleReservation::withReservationAuthorization((string) $locked->id, fn () => $this->invoices->openForCollection($invoice));

            // Re-read the PENDING row the transition just produced: the
            // allocation's previous_status snapshot and the recomputation
            // below must both see it.
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $allocation = $locked->allocation_id !== null
                ? PaymentAllocation::query()->whereKey($locked->allocation_id)->firstOrFail()
                : Ledger::createAllocation((string) $payment->id, $invoice, $payment->amount, $payment->paid_at); // the locked payment instant, as VoucherSaleService does (gate round 4)

            $invoice->recomputeCollectionState();

            $locked->allocation_id = (string) $allocation->id;
            $locked->stage         = SaleReservationStage::Settled;
            $locked->settled_at    = now();
            CheckedWrite::orFail($locked->save(), 'the Settled transition');

            return new OneOffSaleResult(
                $locked->fresh()     ?? $locked,
                $payment->fresh()    ?? $payment,
                $invoice->fresh()    ?? $invoice,
                $allocation->fresh() ?? $allocation,
            );
        });
    }

    /** The settled evidence tuple (D7): the reservation's own rows, re-read. */
    protected function settledEvidence(SaleReservation $reservation): OneOffSaleResult
    {
        $payment    = $reservation->payment()->firstOrFail();
        $invoice    = $reservation->invoice()->firstOrFail();
        $allocation = PaymentAllocation::query()->whereKey($reservation->allocation_id)->firstOrFail();

        return new OneOffSaleResult($reservation->fresh() ?? $reservation, $payment, $invoice, $allocation);
    }

    /**
     * Read-only diagnosis of a one-off sale (spec §4.4, C4): the stage, the
     * ids, and the first incoherence of the persisted tuple against that
     * stage — inside ONE transaction with shared locks in the global order,
     * so it never combines rows from different commits of a stage.
     */
    public function diagnose(string $operationKey): SaleReservation
    {
        return DB::transaction(function () use ($operationKey): SaleReservation {
            $reservation = SaleReservation::query()->where('operation_key', $operationKey)->sharedLock()->first();

            if ($reservation === null) {
                throw SaleReservationNotFoundException::forKey($operationKey);
            }

            return $reservation;
        });
    }

    /**
     * Refund a settled one-off sale (spec §4.5, D8 rev11) — a mechanism, not
     * a policy: full or partial, repeatable, any time, any reason. No credit
     *_movements row is written, no allocation is reversed, the funding
     * payment stays POSTED: the rectificative documents the reduction, the
     * refund row documents the money that left.
     *
     * Three steps over a durable intent, every verification read through the
     * bare-PDO primitive (RawDocumentRead):
     *
     *   R0 replay first — the frozen payload matches or conflicts; routing
     *      by the row's own state (document status and stage);
     *   R1 the durable intent — the resolved amount frozen, capacity
     *      reserved under the reservation lock, the COMPLETE fiscal plan and
     *      the frozen sources computed and stored ON the intent;
     *   R2 refund-owned issuance — both links in the phase-1 commit, the
     *      pre-number checks inside issuance (currency, intent stage, frozen
     *      recipient, plan equality incl. header), the rectificative born
     *      collection-DRAFT (no post-issuance stamp reused);
     *   R3 the split — the close (primitive as its last word), post-commit
     *      detection on the write connection, then the intent flip as the
     *      refund's LAST write in its own transaction, with the primitive as
     *      that transaction's last statement.
     *
     * @throws SaleReservationNotFoundException
     * @throws SaleStageException not Settled
     * @throws InsufficientRefundableException
     * @throws IdempotencyConflictException
     */
    public function refund(SaleRefundRequest $request): SaleRefundResult
    {
        OutermostTransaction::assert(__METHOD__);

        // R0 — replay first, no writes.
        $existing = SaleRefund::query()->where('refund_key', $request->refundKey)->first();

        if ($existing !== null) {
            $this->assertRefundPayloadMatches($existing, $request);

            return $this->resumeRefund($existing);
        }

        $reservation = $this->findReservation($request->operationKey);

        if ($reservation->stage !== SaleReservationStage::Settled) {
            throw SaleStageException::forRelease($reservation->stage, $request->operationKey);
        }

        $intent = $this->writeRefundIntent($reservation, $request);   // R1

        return $this->issueRefund($intent, $request);                 // R2 + R3
    }

    /** R0: the frozen payload must match the request (same key, same money). */
    protected function assertRefundPayloadMatches(SaleRefund $existing, SaleRefundRequest $request): void
    {
        if ($existing->sale_reservation_id === null
            || (string) $existing->sale_reservation_id                                 !== $this->findReservation($request->operationKey)->id
            || $request->currency                                                      !== $existing->currency
            || $request->method                                                        !== $existing->method
            || $request->reference                                                     !== $existing->reference
            || $request->recordedBy                                                    !== $existing->recorded_by
            || $request->amount?->toScale(2)->compareTo($existing->amount->toScale(2)) !== 0
            || $existing->refunded_at->copy()->utc()->format('Y-m-d H:i:s')            !== Carbon::instance($request->refundedAt)->utc()->format('Y-m-d H:i:s')) {
            throw IdempotencyConflictException::forKey($request->refundKey);
        }
    }

    /**
     * The deterministic R1 recomputation of a plan-less intent's plan (R1 always stores one).
     *
     * @return array{is_full_clone: bool, frozen_sources: array<string, mixed>, entries: array<int, array<string, mixed>>}
     */
    protected function rebuildPlanFor(SaleRefund $intent): array
    {
        $reservation = $intent->reservation()->firstOrFail();
        $source      = RawDocumentRead::row('invoices', (string) $reservation->invoice()->firstOrFail()->id);

        if ($source === null) {
            throw SaleIntegrityException::because(
                SaleIntegrityReason::InvoiceNotIssued,
                (string) $reservation->id,
                (string) $reservation->invoice()->firstOrFail()->id,
                'the settled sale invoice is not readable through the primitive at the plan rebuild'
            );
        }

        return $this->buildRefundPlan($source, $intent->amount->toScale(2)->unscaledValue(), $reservation);
    }

    /** R0 routing: by the row's own state — terminal, or resume at its stage. */
    protected function resumeRefund(SaleRefund $intent): SaleRefundResult
    {
        $reservation = $intent->reservation()->firstOrFail();

        if ($intent->stage === SaleRefundStage::Abandoned) {
            return new SaleRefundResult($intent, null, $reservation->remainingRefundable()); // terminal, no document
        }

        if ($intent->stage === SaleRefundStage::Refunded) {
            return new SaleRefundResult($intent, $intent->rectificative()->first(), $reservation->remainingRefundable());
        }

        // Intended: resume by the document, when there is one.
        // A plan-less intent is a foreign cut this service never wrote (R1
        // ALWAYS stores the plan); rebuild it from the frozen expectation —
        // the same deterministic computation R1 performs, no drift possible.
        if ($intent->bucket_plan === null) {
            /** @var array{is_full_clone: bool, frozen_sources: array<string, mixed>, entries: array<int, array<string, mixed>>} $rebuilt */
            $rebuilt             = $this->rebuildPlanFor($intent);
            $intent->bucket_plan = $rebuilt;
            CheckedWrite::orFail($intent->save(), 'the rebuilt plan');
            $intent = $intent->fresh() ?? $intent;
        }

        if ($intent->rectificative_invoice_id !== null) {
            $raw = RawDocumentRead::row('invoices', (string) $intent->rectificative_invoice_id);

            if ($raw !== null && ($raw['invoice_document_status'] ?? null) !== (int) InvoiceDocumentStatus::ISSUED->value) {
                $fresh = $intent->fresh() ?? $intent;

                return $this->issueRefund($fresh, $this->intentRequest($fresh)); // R2 resume: an unissued draft
            }

            return $this->closeRefund($intent->fresh() ?? $intent); // R3 resume: issued, collection open or closed
        }

        $fresh = $intent->fresh() ?? $intent;

        return $this->issueRefund($fresh, $this->intentRequest($fresh));
    }

    /** The request a stored intent stands for (replay/resume equivalence). */
    protected function intentRequest(SaleRefund $intent): SaleRefundRequest
    {
        return new SaleRefundRequest(
            $intent->reservation()->firstOrFail()->operation_key,
            $intent->refund_key,
            $intent->amount,
            $intent->currency,
            $intent->refunded_at,
            $intent->method,
            $intent->reference,
            $intent->recorded_by,
            $intent->reason,
        );
    }

    /**
     * R1 — the durable intent (spec §4.5 R1): resolve and freeze the amount,
     * reserve capacity under the reservation lock, freeze the COMPLETE plan
     * and the frozen sources ON the row.
     */
    protected function writeRefundIntent(SaleReservation $reservation, SaleRefundRequest $request): SaleRefund
    {
        return DB::transaction(function () use ($reservation, $request): SaleRefund {
            $payment  = GroupedPayment::query()->whereKey($reservation->grouped_payment_id)->lockForUpdate()->firstOrFail();
            $invoice  = Invoice::query()->whereKey($reservation->invoice()->firstOrFail()->id)->lockForUpdate()->firstOrFail();
            $original = Invoice::query()->whereKey($invoice->rectifies_invoice_id ?? $invoice->id)->lockForUpdate()->firstOrFail();
            $locked   = SaleReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage !== SaleReservationStage::Settled) {
                throw SaleStageException::forRelease($locked->stage, $request->operationKey);
            }

            // Capacity: ONE non-Abandoned predicate (V4), under the lock.
            $remaining = $locked->remainingRefundable();
            $resolved  = $request->amount ?? $remaining;

            if ($resolved->isPositive() !== true || $resolved->toScale(2)->compareTo($remaining->toScale(2)) > 0) {
                throw InsufficientRefundableException::forRefund($locked, $resolved, $remaining);
            }

            // The sale's settled invoice: the ORIGINAL the rectificative
            // corrects. The plan source read goes through the primitive
            // (R9-S1: no hydration, no retrieved poisoning).
            $saleInvoiceId = $original->sale_reservation_id !== null ? (string) $original->id : null;
            $source        = $saleInvoiceId                 !== null ? RawDocumentRead::row('invoices', $saleInvoiceId) : null;

            if ($source === null) {
                throw SaleIntegrityException::because(
                    SaleIntegrityReason::InvoiceNotIssued,
                    (string) $locked->id,
                    (string) $original->id,
                    'the settled sale invoice is not readable through the primitive at R1'
                );
            }

            // The frozen sources (R8-3, R9-3): the original's persisted payloads verbatim.
            $frozenSources = [
                'customer_snapshot' => (string) $source['customer_snapshot'],
                'fiscal_recipient'  => $this->fiscalRecipientOf($source),
            ];

            // The plan (V1, R5-1): pristine full refund = per-line verbatim clone;
            // otherwise the COMPLETE component allocation per rate bucket.
            $plan = $this->buildRefundPlan($source, $resolved->toScale(2)->unscaledValue(), $locked);

            $intent = new SaleRefund([
                'sale_reservation_id' => (string) $locked->id,
                'refund_key'          => $request->refundKey,
                'stage'               => SaleRefundStage::Intended,
                'amount'              => $resolved,
                'bucket_plan'         => $plan,
                'currency'            => $request->currency,
                'refunded_at'         => $request->refundedAt,
                'method'              => $request->method,
                'reference'           => $request->reference,
                'recorded_by'         => $request->recordedBy,
                'reason'              => $request->reason,
            ]);
            $intent->save();

            return $intent->fresh() ?? $intent;
        });
    }

    /**
     * The recipient-derived fiscal fields of a raw invoice row (R8-3).
     *
     * @param  array<string, mixed>  $row
     * @return array{tax_id: string|null, country: string|null, intra_community: bool, is_vat_registered: bool}
     */
    protected function fiscalRecipientOf(array $row): array
    {
        $snapshot = $row['fiscal_snapshot'] ?? null;
        $fields   = ['tax_id' => null, 'country' => null, 'intra_community' => false, 'is_vat_registered' => false];

        if (is_string($snapshot) && $snapshot !== '') {
            try {
                $decoded = json_decode(decrypt($snapshot), true, 512, JSON_THROW_ON_ERROR);

                $fields['tax_id']            = $decoded['customer_tax_id']  ?? null;
                $fields['country']           = $decoded['customer_country'] ?? null;
                $fields['intra_community']   = (bool) ($decoded['customer_is_intra_community'] ?? false);
                $fields['is_vat_registered'] = (bool) ($decoded['customer_is_vat_reg'] ?? false);
            } catch (\Throwable) {
                // A malformed snapshot is caught by the byte comparison later.
            }
        }

        return $fields;
    }

    /**
     * The COMPLETE frozen fiscal plan (spec §D8 rev11, §4.5 R1(c)):
     * pristine full refund (no prior non-Abandoned refunds and the resolved
     * gross equals the original's total) = the original's lines VERBATIM,
     * sign-flipped, per line. Otherwise: aggregation into rate buckets and a
     * pro-rata component allocation against the REMAINING bucket amounts,
     * largest-remainder correction, terminal sweep.
     *
     * @return array{is_full_clone: bool, frozen_sources: array{customer_snapshot: string, fiscal_recipient: array{tax_id: string|null, country: string|null, intra_community: bool, is_vat_registered: bool}}, entries: array<int, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}>}
     */
    /**
     * @param  array<string, mixed>  $source
     * @return array{is_full_clone: bool, frozen_sources: array{customer_snapshot: string, fiscal_recipient: array{tax_id: string|null, country: string|null, intra_community: bool, is_vat_registered: bool}}, entries: array<int, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}>}
     */
    protected function buildRefundPlan(array $source, int $resolvedGross, SaleReservation $reservation): array
    {
        $items = DB::connection()->getPdo()->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id ASC');
        $items->execute([':id' => $source['id']]);

        $frozenSources = [
            'customer_snapshot' => (string) $source['customer_snapshot'],
            'fiscal_recipient'  => $this->fiscalRecipientOf($source),
        ];

        $priorRefunded = (int) SaleRefund::query()
            ->where('sale_reservation_id', $reservation->id)
            ->where('stage', '!=', SaleRefundStage::Abandoned->value)
            ->sum('amount');

        $originalGross = (int) (((int) $source['taxable_amount']) + ((int) $source['total_tax_amount']));

        // The pristine path: per-line verbatim clone of the original's lines.
        if ($priorRefunded === 0 && $resolvedGross === $originalGross) {
            $entries = [];

            while ($line = $items->fetch(\PDO::FETCH_ASSOC)) {
                $entries[] = [
                    'source_rate_id' => $this->lineRateId($line),
                    'rate'           => $this->lineRate($line),
                    'base'           => -((int) $line['taxable_amount']),
                    'tax'            => -((int) $line['total_tax_amount']),
                    'gross'          => -((int) $line['total_amount']),
                    'taxes'          => $this->lineTaxes($line),
                ];
            }

            return ['is_full_clone' => true, 'frozen_sources' => $frozenSources, 'entries' => $entries];
        }

        // The allocation path: aggregate the original's lines into rate buckets.
        $buckets = $this->rateBuckets($source, $items);

        // Remaining bucket amounts: frozen minus prior COMPLETE plans.
        foreach ($this->priorPlans($reservation) as $plan) {
            /** @var array<int, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}> $priorEntries */
            $priorEntries = $plan['entries'] ?? [];
            foreach ($priorEntries as $entry) {
                $key = ((int) ($entry['source_rate_id'] ?? 0)).'|'.((int) ($entry['rate'] ?? 0));

                if (! array_key_exists($key, $buckets)) {
                    continue; // a foreign bucket never carried by this original
                }

                $multiplier = (($plan['is_full_clone'] ?? false) === true) ? -1 : 1;
                $buckets[$key]['gross'] += $multiplier * abs((int) ($entry['gross'] ?? 0));
                $buckets[$key]['base']  += $multiplier * abs((int) ($entry['base'] ?? 0));
                $buckets[$key]['tax']   += $multiplier * abs((int) ($entry['tax'] ?? 0));
            }
        }

        // The terminal sweep: taking the whole remaining refundable sweeps
        // every bucket to exactly 0 (R6-1).
        $isTerminal = $resolvedGross === ($originalGross - $priorRefunded);

        $entries = $this->allocateBuckets($buckets, $resolvedGross, $isTerminal);

        return ['is_full_clone' => false, 'frozen_sources' => $frozenSources, 'entries' => $entries];
    }

    /** @return array<string, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}> */
    /**
     * @param  array<string, mixed>  $source
     * @param  \PDOStatement<array<string, mixed>>  $items
     * @return array<string, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}>
     */
    protected function rateBuckets(array $source, \PDOStatement $items): array
    {
        $buckets = [];

        while ($line = $items->fetch(\PDO::FETCH_ASSOC)) {
            $rateId = $this->lineRateId($line);
            $rate   = $this->lineRate($line);
            $key    = ((int) $rateId).'|'.((int) $rate);

            $buckets[$key] ??= ['source_rate_id' => $rateId, 'rate' => $rate, 'base' => 0, 'tax' => 0, 'gross' => 0, 'taxes' => []];
            $buckets[$key]['base']  += (int) $line['taxable_amount'];
            $buckets[$key]['tax']   += (int) $line['total_tax_amount'];
            $buckets[$key]['gross'] += (int) $line['total_amount'];

            if ($buckets[$key]['taxes'] === [] && $this->lineTaxes($line) !== []) {
                $buckets[$key]['taxes'] = $this->lineTaxes($line);
            }
        }

        return $buckets;
    }

    /** The COMPLETE component allocation (V1, R5-1, R6-1). */
    /**
     * @param  array<string, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}>  $buckets
     * @return array<int, array{source_rate_id: int|null, rate: int, base: int, tax: int, gross: int, taxes: array<int, array{source_rate_id: int|null, rate: int, amount: int}>}>
     */
    protected function allocateBuckets(array $buckets, int $gross, bool $terminal): array
    {
        $totalGross = 0;

        foreach ($buckets as $bucket) {
            $totalGross += $bucket['gross'];
        }

        if ($totalGross <= 0 || $gross <= 0) {
            return [];
        }

        $entries   = [];
        $allocated = 0;

        if ($terminal) {
            // The sweep: every remaining component, verbatim, signed by its bucket.
            foreach ($buckets as $bucket) {
                if ($bucket['base'] === 0 && $bucket['tax'] === 0 && $bucket['gross'] === 0) {
                    continue;
                }

                $entries[] = [
                    'source_rate_id' => $bucket['source_rate_id'],
                    'rate'           => $bucket['rate'],
                    'base'           => -$bucket['base'],
                    'tax'            => -$bucket['tax'],
                    'gross'          => -$bucket['gross'],
                    'taxes'          => $this->signFlippedTaxes($bucket['taxes'], $bucket['tax']),
                ];
            }

            return $entries;
        }

        // Pro-rata gross with largest-remainder correction.
        $shares = [];

        foreach ($buckets as $key => $bucket) {
            $exact        = $gross * $bucket['gross'];
            $floor        = intdiv($exact, $totalGross);
            $shares[$key] = $floor;
            $allocated += $floor;
        }

        $remainder = $gross - $allocated;

        // Largest remainder first (stable: bucket order).
        $order = [];

        foreach ($buckets as $key => $bucket) {
            $order[$key] = ($gross * $bucket['gross']) % $totalGross;
        }

        arsort($order);

        foreach (array_keys($order) as $key) {
            if ($remainder <= 0) {
                break;
            }

            if ($buckets[$key]['gross'] !== 0) {
                $shares[$key] += (int) ($buckets[$key]['gross'] > 0);
                $remainder--;
            }
        }

        foreach ($buckets as $key => $bucket) {
            $grossB = $shares[$key];

            if ($grossB === 0) {
                continue; // a bucket allocated 0 emits no line (R3-1)
            }

            $base = $bucket['gross'] === 0 ? 0 : (int) round($grossB * $bucket['base'] / $bucket['gross']);
            $tax  = $grossB - $base;

            $entries[] = [
                'source_rate_id' => $bucket['source_rate_id'],
                'rate'           => $bucket['rate'],
                'base'           => -$base,
                'tax'            => -$tax,
                'gross'          => -$grossB,
                'taxes'          => $this->signFlippedTaxes($bucket['taxes'], $tax),
            ];
        }

        return $entries;
    }

    /** @return array<int, array{source_rate_id: int|null, rate: int, amount: int}> */
    /**
     * @param  array<int, array{source_rate_id: int|null, rate: int, amount: int}>  $taxes
     * @return array<int, array{source_rate_id: int|null, rate: int, amount: int}>
     */
    protected function signFlippedTaxes(array $taxes, int $tax): array
    {
        if ($taxes === []) {
            return [];
        }

        // Split the bucket tax over its tax entries proportionally, sign-flipped.
        $total = 0;

        foreach ($taxes as $entry) {
            $total += abs((int) ($entry['amount'] ?? 0));
        }

        /** @var array<int, array{source_rate_id: int|null, rate: int, amount: int}> $out */
        $out    = [];
        $amount = 0;

        foreach ($taxes as $i => $entry) {
            $share = $total === 0 ? 0 : (int) round($tax * abs((int) ($entry['amount'] ?? 0)) / $total);
            $amount += $share;
            $out[(int) $i] = [
                'source_rate_id' => array_key_exists('source_rate_id', $entry) ? (int) $entry['source_rate_id'] : null,
                'rate'           => (int) ($entry['rate'] ?? 0),
                'amount'         => -$share,
            ];
        }

        // Correct rounding residue on the last entry.
        if ($out !== []) {
            $lastKey = array_key_last($out);
            $out[$lastKey]['amount'] += $tax - $amount;
        }

        return $out;
    }

    /** @return array<int, mixed> the prior stored plans of the sale's refunds */
    protected function priorPlans(SaleReservation $reservation): array
    {
        $plans = [];

        foreach (SaleRefund::query()
            ->where('sale_reservation_id', $reservation->id)
            ->where('stage', '!=', SaleRefundStage::Abandoned->value)
            ->whereNotNull('bucket_plan')
            ->get() as $refund) {
            $plan = $refund->bucket_plan;

            if (is_array($plan)) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /** @param  array<string, mixed>  $line */
    protected function lineRateId(array $line): ?int
    {
        $taxes = $this->lineTaxes($line);

        return $taxes === [] ? null : (int) ($taxes[0]['source_rate_id'] ?? 0);
    }

    /** @param  array<string, mixed>  $line */
    protected function lineRate(array $line): int
    {
        $taxes = $this->lineTaxes($line);

        return $taxes === [] ? 0 : (int) ($taxes[0]['rate'] ?? 0);
    }

    /** @return array<int, array{source_rate_id: int|null, rate: int, amount: int}> */
    /**
     * @param  array<string, mixed>  $line
     * @return array<int, array{source_rate_id: int|null, rate: int, amount: int}>
     */
    protected function lineTaxes(array $line): array
    {
        $raw = $line['taxes_applied'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $out[] = ['source_rate_id' => $entry['source_rate_id'] ?? null, 'rate' => (int) ($entry['rate'] ?? 0), 'amount' => (int) ($entry['amount'] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * R2 — refund-owned issuance (spec §4.5 R2): both links in the phase-1
     * commit, the rectificative born collection-DRAFT, the pre-number checks
     * inside issuance, then R3.
     */
    protected function issueRefund(SaleRefund $intent, SaleRefundRequest $request): SaleRefundResult
    {
        $rectificative = $this->createRefundRectificative($intent, $request); // R2

        $fresh = $intent->fresh() ?? $intent;

        return $this->closeRefund($fresh);               // R3
    }

    /** R2: the two-phase creation with both links, then issuance under the intent. */
    protected function createRefundRectificative(SaleRefund $intent, SaleRefundRequest $request): Invoice
    {
        $reservation = $intent->reservation()->firstOrFail();
        $original    = $reservation->invoice()->firstOrFail(); // the settled sale invoice: the document the rectificative corrects

        // R2a fast-fail pre-check: a stray keyed draft without the link is a conflict.
        $stray = Invoice::query()->where('settlement_operation_key', $request->refundKey)->first();

        if ($stray !== null && (string) $stray->sale_refund_id !== (string) $intent->id) {
            throw IdempotencyConflictException::forKey($request->refundKey);
        }

        if ($stray !== null && $stray->invoice_document_status === InvoiceDocumentStatus::ISSUED) {
            return $stray; // a cut run's issued casualty: adopt and continue to R3
        }

        if ($stray !== null) {
            // An unissued draft of THIS intent: resume issuance.
            return SaleReservation::withReservationAuthorization((string) $reservation->id, function () use ($stray, $intent): Invoice {
                $issued = $this->invoices->issue($stray);

                $this->assertRefundPreNumberChecks($intent, $issued);

                return $issued;
            });
        }

        // The two-phase creation: phase 1 = the durable unnumbered DRAFT with
        // BOTH links; the intent's rectificative_invoice_id in the SAME commit.
        $draft = DB::transaction(function () use ($intent, $original, $request): Invoice {
            $created = $this->invoices->createInvoice([
                'billable_user_id'         => (string) $original->billable_user_id,
                'type'                     => 'rectificative',
                'rectifies_invoice_id'     => $original->id,
                'settlement_operation_key' => $request->refundKey,
                'status'                   => InvoiceStatus::DRAFT->value,
                'items'                    => $this->planItems($intent),
                'is_roi_taxed'             => (bool) $original->is_roi_taxed,
                'customer_snapshot'        => $intent->bucket_plan['frozen_sources']['customer_snapshot'] ?? null,
                'user_tax_profile_id'      => $original->user_tax_profile_id !== null ? (string) $original->user_tax_profile_id : null,
            ], ['make_immutable' => true]);

            // The invoice-side link is written by the refund's creation only
            // (the sale_claim_id doctrine: never fillable, written by the raw
            // update of its own flow - AID-1825 S2). BOTH links commit with
            // the phase-1 draft (R3-3).
            Invoice::query()->whereKey($created->getKey())->update(['sale_refund_id' => (string) $intent->id]);

            $intent->rectificative_invoice_id = (string) $created->id;
            CheckedWrite::orFail($intent->save(), 'the intent-rectificative link');

            // Phase-1 validation (R6-2): lines AND header re-read through the
            // primitive and compared with the stored plan before the commit.
            $this->assertRefundPlanMatches($intent, $created);

            return $created;
        });

        // Phase 2: issuance as its own outermost transaction, under the
        // reservation's authorization (the issue() guards).
        return SaleReservation::withReservationAuthorization((string) $reservation->id, function () use ($draft, $intent): Invoice {
            $issued = $this->invoices->issue($draft);

            $this->assertRefundPreNumberChecks($intent, $issued);

            return $issued;
        });
    }

    /** The line items of the stored plan, verbatim. */
    /** @return array<int, array<string, mixed>> */
    protected function planItems(SaleRefund $intent): array
    {
        $plan   = $intent->bucket_plan ?? [];
        $items  = [];

        foreach ($plan['entries'] ?? [] as $entry) {
            $items[] = [
                'item_type'        => null,
                'description'      => 'Refund of sale invoice',
                'quantity'         => 100,
                'unit_price'       => (int) $entry['gross'],
                'taxable_amount'   => (int) $entry['base'],
                'total_tax_amount' => (int) $entry['tax'],
                'taxes_applied'    => array_map(fn (array $tax): array => [
                    'source_rate_id' => $tax['source_rate_id'],
                    'name'           => null,
                    'rate'           => $tax['rate'],
                    'amount'         => $tax['amount'],
                ], $entry['taxes'] ?? []),
                'total_amount'   => (int) $entry['gross'],
            ];
        }

        return $items;
    }

    /**
     * The pre-number checks inside issuance (R3-5, R8-2, R9): currency on the
     * pricing config, the emitted recipient, and the plan equality incl. the
     * header — all through the primitive, as the verification of record.
     */
    protected function assertRefundPreNumberChecks(SaleRefund $intent, Invoice $issued): void
    {
        $raw = RawDocumentRead::row('invoices', (string) $issued->id);

        if ($raw === null) {
            throw SaleIntegrityException::because(SaleIntegrityReason::InvoiceNotIssued, (string) $intent->id, (string) $issued->id, 'the rectificative is not readable through the primitive at the pre-number check');
        }

        $plan = $intent->bucket_plan ?? [];
        $sums = ['base' => 0, 'tax' => 0, 'gross' => 0];

        foreach ($plan['entries'] ?? [] as $entry) {
            $sums['base']  += (int) $entry['base'];
            $sums['tax']   += (int) $entry['tax'];
            $sums['gross'] += (int) $entry['gross'];
        }

        if ((int) $raw['taxable_amount']      !== $sums['base']
            || (int) $raw['total_tax_amount'] !== $sums['tax']
            || (int) $raw['total_amount']     !== $sums['gross']) {
            throw SaleIntegrityException::because(
                SaleIntegrityReason::ContentDrift,
                (string) $intent->id,
                (string) $issued->id,
                'the persisted rectificative header does not equal the frozen plan (pre-number check)'
            );
        }

        // The frozen recipient: the customer snapshot byte for byte.
        if ((string) $raw['customer_snapshot'] !== (string) ($plan['frozen_sources']['customer_snapshot'] ?? '')) {
            throw SaleIntegrityException::because(
                SaleIntegrityReason::PayerDrift,
                (string) $intent->id,
                (string) $issued->id,
                'the emitted rectificative recipient does not equal the frozen source (pre-number check)'
            );
        }
    }

    /** The phase-1 plan validation (R6-2): lines AND header vs the stored plan. */
    protected function assertRefundPlanMatches(SaleRefund $intent, Invoice $draft): void
    {
        $this->assertRefundPreNumberChecks($intent, $draft);
    }

    /**
     * R3 — the split (spec §4.5 R3 rev11): (a) the close with the primitive
     * as its last word; (b) post-commit detection on the write connection;
     * (c) the intent flip as the refund's LAST write in its own transaction,
     * the primitive as that transaction's last statement.
     */
    protected function closeRefund(SaleRefund $intent): SaleRefundResult
    {
        $reservation = $intent->reservation()->firstOrFail();

        // (a) The close, one transaction, primitive as its last word.
        DB::transaction(function () use ($intent): void {
            $rectificative = Invoice::query()->whereKey($intent->rectificative_invoice_id)->lockForUpdate()->firstOrFail();

            if ($rectificative->invoice_document_status !== InvoiceDocumentStatus::ISSUED) {
                throw SaleIntegrityException::because(
                    SaleIntegrityReason::InvoiceNotIssued,
                    (string) $intent->id,
                    (string) $rectificative->id,
                    'the rectificative is not ISSUED at the close'
                );
            }

            if ($rectificative->status === InvoiceStatus::DRAFT) {
                // G17: the refund's own named writer is the only closer of a
                // refund rectificative's collection axis (never payable by
                // allocation, §5.1.3 — recomputeCollectionState() cannot).
                CheckedWrite::orFail($rectificative->markRefundClosed($intent->refunded_at), 'the refund close');
            }

            // The close's last word: the primitive re-verifies the persisted
            // document before the commit (R8-4).
            $this->assertRefundPreNumberChecks($intent->fresh() ?? $intent, $rectificative);
        });

        // (b) Post-commit detection on the write connection (R8-2): a
        // committing-callback forgery leaves the refund UNCOMPLETED.
        $this->assertRefundPreNumberChecks($intent->fresh() ?? $intent, $intent->rectificative()->firstOrFail());

        // (c) The intent flip — the refund's LAST write, its own transaction,
        // the primitive as that transaction's last statement (R9-1, R9-5).
        DB::transaction(function () use ($intent): void {
            $locked = SaleRefund::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage === SaleRefundStage::Intended) {
                $locked->stage = SaleRefundStage::Refunded;
                CheckedWrite::orFail($locked->save(), 'the Refunded flip');
            }

            $this->assertRefundPreNumberChecks($locked->fresh() ?? $locked, $locked->rectificative()->firstOrFail());
        });

        $final = $intent->fresh() ?? $intent;

        $reservationFresh = $reservation->fresh() ?? $reservation;

        return new SaleRefundResult($final, $final->rectificative()->first(), $reservationFresh->remainingRefundable());
    }

    /**
     * The typed reservability list (spec §4.1 step 5): a proforma of the
     * PROFORMA serie, collection status in {DRAFT, SENT}, never CONVERTING
     * or CONVERTED, not claimed by a voucher sale, a positive total, and a
     * fiscal config currency to freeze.
     */
    protected function assertReservable(Invoice $proforma): void
    {
        if ($proforma->serie === null || $proforma->serie->value !== InvoiceSerieType::PROFORMA->value) {
            throw SaleNotReservableException::notAProforma((string) $proforma->id);
        }

        if (! $proforma->status instanceof InvoiceStatus || ! in_array($proforma->status, [InvoiceStatus::DRAFT, InvoiceStatus::SENT], true)) {
            $status = $proforma->status === null ? -1 : $proforma->status->value;

            throw SaleNotReservableException::forStatusValue((string) $proforma->id, $status);
        }

        if ($proforma->getAttribute('converted_invoice_id') !== null || $proforma->getAttribute('converted_at') !== null) {
            throw SaleNotReservableException::forConverted((string) $proforma->id);
        }

        if ($proforma->getAttribute('sale_claim_id') !== null) {
            throw SaleNotReservableException::forVoucherClaim((string) $proforma->id, (string) $proforma->getAttribute('sale_claim_id'));
        }

        if (! $proforma->total_amount->isPositive()) {
            throw SaleNotReservableException::forNonPositiveTotal((string) $proforma->id);
        }

        if ($this->proformaCurrency($proforma) === null) {
            throw SaleNotReservableException::forNoCurrency((string) $proforma->id);
        }
    }

    /** The proforma's fiscal config currency (the frozen expectation's currency), or null. */
    protected function proformaCurrency(Invoice $proforma): ?string
    {
        return $proforma->companyFiscalConfig?->currency;
    }
}
