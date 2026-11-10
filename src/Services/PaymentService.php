<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\AllocationExceedsPaymentException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use AichaDigital\Larabill\Exceptions\SaleClaimedException;
use AichaDigital\Larabill\Exceptions\SaleStageException;
use AichaDigital\Larabill\Models\CreditMovement;
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
use AichaDigital\Larabill\ValueObjects\Allocation;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Money received from a customer, its allocations to issued invoices and the
 * customer credit it leaves behind (AID-962, spec §5).
 *
 * Every write runs in ONE DB::transaction. Global lock order, so that no two
 * operations cross: grouped_payments rows first (by id ascending), invoices
 * rows after (by id ascending). receive() locks invoices only. Every TRANSITION
 * of an invoice's collection state (`status`, `paid_at`) is written by
 * Invoice::recomputeCollectionState() on the instance re-read under lock —
 * the single writer of collection-state transitions (the birth status and
 * SettlementService::annul()'s CANCELLED are written elsewhere).
 *
 * @api Supported public surface (AID-962; see docs/api-surface.md).
 */
class PaymentService
{
    /**
     * Record a payment received from $billableUserId and allocate it to
     * issued invoices (spec §5.1). Whatever is not allocated becomes ONE
     * credit entry of $creditKind. A replayed key returns the payment it names
     * when the full immutable payload matches (ReplayPolicy::Full).
     *
     * Must be the OUTERMOST transaction (AID-1884): inside a caller's
     * transaction that has already read, the outstanding of each invoice
     * would be summed from the caller's REPEATABLE READ snapshot and the
     * call could allocate past the invoice total. Guarded: above
     * `larabill.payments.base_transaction_level` (default 0; 1 under
     * RefreshDatabase) it throws before reading or validating anything — a
     * same-key replay and an invalid payload included.
     *
     * @param  array<int, Allocation>  $allocations
     *
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     * @throws GroupedPaymentValidationException on an invalid amount, kind, duplicate ids, or an invoice that is not payable
     * @throws AllocationExceedsPaymentException when the allocations add up to more than $amount
     * @throws AllocationExceedsInvoiceException when an allocation exceeds the invoice outstanding
     * @throws IdempotencyConflictException when the key names a reversed payment or a different payload
     */
    public function receive(
        string $billableUserId,
        FixedDecimal $amount,
        string $currency,
        DateTimeInterface $receivedAt,
        PaymentMethod $method,
        ?string $reference,
        ?string $recordedBy,
        array $allocations,
        CreditMovementKind $creditKind = CreditMovementKind::SURPLUS,
        ?string $idempotencyKey = null,
    ): GroupedPayment {
        OutermostTransaction::assert(__METHOD__); // AID-1884: before any read or validation

        return $this->process(
            ReceiveRequest::fromReceive($billableUserId, $amount, $currency, $receivedAt, $method, $reference, $recordedBy, $allocations, $creditKind, $idempotencyKey),
            ReplayPolicy::Full,
        );
    }

    /**
     * The receive() transaction (spec §5.1): invoice lock by id, key
     * pre-check, the §5.1.3 validation list on the locked instances, the
     * payment, one allocation row per invoice, the recomputation, and the
     * remainder entry. A lost race on the idempotency key UNIQUE re-reads the
     * winner and applies the SAME replayMatches($raced, $request, $policy).
     *
     * Snapshot rule (AID-962 fix round 1): under REPEATABLE READ the snapshot
     * is fixed by the first consistent read; the locking read must come first
     * so every later sum sees what the previous holder of the invoice lock
     * committed. Do not add a plain read before it. (A locking aggregate over
     * the pivot was rejected: on an empty range it is a gap lock shared across
     * invoices, and it deadlocked writers of different invoices — AID-715.)
     *
     * Call it as the OUTERMOST transaction: inside a caller's transaction ours
     * is a savepoint, and a plain read the caller made earlier has already
     * fixed the snapshot, so every sum here would read stale rows (measured:
     * 140,00 allocated against an invoice of 100,00, AID-1884). This method
     * is not guarded itself: its public callers are — receive() and
     * GroupedPaymentService::register() refuse above
     * `larabill.payments.base_transaction_level`
     * (PaymentOutsideTransactionRequiredException) as their first statement.
     * A direct (internal) caller must be the outermost transaction or run
     * inside a guarded entry point; VoucherSaleService::claim() calls it
     * inside its own transaction with a request that carries no allocations
     * and no remainder entry, so it never reaches the stale sums.
     *
     * Known limit (spec §13 row 43): inside a caller transaction a same-key
     * race reaches the catch as a savepoint rollback and the re-read is
     * served from the pinned snapshot, so it surfaces as
     * IdempotencyConflictException::forKey instead of a replay. Fail-safe (no
     * duplicate money); outside the outermost-transaction contract.
     *
     * Under ReplayPolicy::Legacy the §5.1.3 status list is register()'s
     * (SENT, OVERDUE, PENDING — and PARTIALLY_PAID since 9.0.0, AID-1778:
     * Ledger::legacyPayableStatuses()). The between-transactions race with a
     * register() attempt has TWO cases since 9.0.0, decided by the check order
     * below (status first, then outstanding): a PARTIAL receive() committed
     * between register()'s eligibility check and this transaction leaves the
     * invoice PARTIALLY_PAID with a smaller outstanding than the allocation
     * built earlier and surfaces as AllocationExceedsInvoiceException; a
     * fully-settling one makes the invoice PAID and still surfaces as
     * notPayableStatus (spec §3.4). A same-key replay locks the invoices like
     * any call, but skips the payability and outstanding checks and writes
     * nothing, so outstanding drift between attempts neither invalidates it
     * nor moves new money (spec §3.5).
     *
     * @throws LogicException when $request is replay-only (ReceiveRequest::forLegacyReplay())
     *
     * @internal Shared by receive() (Full) and GroupedPaymentService::register() (Legacy).
     */
    public function process(ReceiveRequest $request, ReplayPolicy $policy): GroupedPayment
    {
        if ($request->replayOnly) {
            throw new LogicException('PaymentService::process(): a ReceiveRequest built by forLegacyReplay() carries placeholder allocations and must never feed a write.');
        }

        $key = $request->idempotencyKey;

        try {
            return DB::transaction(function () use ($request, $policy, $key): GroupedPayment {
                // FIRST statement: the locking read (see the snapshot rule above).
                $invoices = Ledger::lockInvoices($request->invoiceIds());

                $existing = GroupedPayment::where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $request, $policy);
                }

                Ledger::assertAllFound($request->invoiceIds(), $invoices);
                $invoices->load('companyFiscalConfig');

                $statuses = Ledger::payableStatusesFor($policy);
                foreach ($request->allocations as $allocation) {
                    /** @var Invoice $invoice present: assertAllFound() rejects missing ids */
                    $invoice = $invoices->get($allocation->invoiceId);
                    Ledger::assertPayable($invoice, $request->billableUserId, $request->currency, $statuses);
                    Ledger::assertNotClaimedSale($invoice, 'allocating a payment'); // AID-1825 §1a rule 2 (covers receive() and register())
                    Ledger::assertWithinOutstanding($invoice, $allocation->amount);
                }

                $payment = new GroupedPayment([
                    'billable_user_id' => $request->billableUserId,
                    'amount'           => $request->amount,
                    'currency'         => $request->currency,
                    'paid_at'          => $request->receivedAt,
                    'reference'        => $request->reference,
                    'idempotency_key'  => $key,
                    'status'           => GroupedPaymentStatus::POSTED,
                    'method'           => $request->method,
                    'recorded_by'      => $request->recordedBy,
                ]);
                // A vetoed payment save must not leave an unsaved model that the
                // rest of the step (allocations, remainder, a sale claim) references.
                CheckedWrite::orFail($payment->save(), 'the received payment');

                foreach ($request->allocations as $allocation) {
                    /** @var Invoice $invoice */
                    $invoice = $invoices->get($allocation->invoiceId);
                    Ledger::createAllocation((string) $payment->id, $invoice, $allocation->amount, $request->receivedAt);
                    $invoice->recomputeCollectionState();
                }

                // AID-1825 S1: a voucher-sale recording keeps the remainder
                // OUT of the ledger — recorded, not spendable, until S3b.
                if ($request->remainderEntry) {
                    $this->recordRemainder($payment, $request);
                }

                return $this->loaded($payment);
            });
        } catch (QueryException $e) {
            // Concurrency backstop: another tx won the unique idempotency_key race (this tx rolled back).
            if (! $this->isIdempotencyKeyViolation($e)) {
                throw $e;
            }

            $raced = GroupedPayment::where('idempotency_key', $key)->first();
            if ($raced !== null && $raced->isPosted() && $this->replayMatches($raced, $request, $policy)) {
                return $this->loaded($raced);
            }

            throw IdempotencyConflictException::forKey($key);
        }
    }

    /**
     * Whether $request names the same immutable payload as $existing (spec
     * §5.1.1, §5.2). Allocations are read from the DIRECT allocation rows of
     * the payment — rows no APPLIED exit points at, active or reversed: they
     * are exactly the ones the original request created, whatever happened
     * to them later.
     *
     * - Legacy: payer, currency, amount, distinct invoice ids.
     * - Full: Legacy + receivedAt (to the second, UTC) + method + reference +
     *   creditKind (only when the payment created an entry) + the per-invoice
     *   allocation map.
     *
     * @internal The single comparison used by the pre-check and the race backstop.
     */
    public function replayMatches(GroupedPayment $existing, ReceiveRequest $request, ReplayPolicy $policy): bool
    {
        if ((string) $existing->billable_user_id              !== $request->billableUserId
            || $existing->currency                            !== $request->currency
            || $existing->amount->compareTo($request->amount) !== 0) {
            return false;
        }

        $direct = $this->directAllocationMap($existing);
        $ids    = array_map('strval', array_keys($direct));

        if ($ids !== $request->invoiceIds()) {
            return false;
        }

        if ($policy === ReplayPolicy::Legacy) {
            return true;
        }

        $format = 'Y-m-d H:i:s';
        if ($existing->paid_at->copy()->utc()->format($format) !== $request->receivedAt->utc()->format($format)) {
            return false;
        }

        if ($existing->method !== $request->method || $existing->reference !== $request->reference) {
            return false;
        }

        $entry = $existing->creditMovements()
            ->entries()
            ->whereNull('allocation_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();

        if ($entry !== null && $entry->kind !== $request->creditKind) {
            return false;
        }

        return $direct === $request->allocationMap();
    }

    /**
     * Allocate part of a POSTED payment's credit to one issued invoice (spec
     * §5.3), funded FIFO from THIS payment's active entries (`occurred_at`,
     * then `id`): one APPLIED exit per entry consumed, all pointing at the
     * new allocation. `$appliedAt` (default now) is the allocation's
     * `applied_at` and the exits' `occurred_at`; `$recordedBy` goes on the
     * exits.
     *
     * Only entries that can fund a payment are spent: a voucher-backed entry
     * (AID-1825) and, since 9.4, an entry linked to an advance invoice
     * (AID-1856) are never taken, and what they hold is not credit this
     * method can allocate — above the rest it refuses, as
     * GroupedPayment::availableCredit() reports.
     *
     * Locks, as the first statements of its transaction (spec §13 row 40):
     * the payment row, then the invoice row; then, after the invoice checks,
     * a row lock on each candidate entry (Ledger::lockFundingEntries()), so
     * whether an entry is linked to an advance is decided on the locked row
     * and a concurrent CreditMovement::linkAdvanceInvoice() either landed
     * before or waits for this call. Every sum is read after the locks.
     * Must be the OUTERMOST transaction (see process()): inside a caller's
     * transaction that has already read, what is left of each entry would
     * still be computed from the caller's snapshot — the entry lock does not
     * change that (measured: 140,00 of exits on an entry of 100,00,
     * AID-1884). Guarded: above `larabill.payments.base_transaction_level`
     * (default 0; 1 under RefreshDatabase) it throws before reading anything.
     *
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     * @throws AllocationExceedsPaymentException when the payment is not POSTED (a reversed payment has no credit) or its entries that can fund a payment cannot cover the amount
     * @throws GroupedPaymentValidationException when the invoice does not exist or is not payable (§5.1.3)
     * @throws AllocationExceedsInvoiceException when the amount exceeds the invoice outstanding
     * @throws LogicException when a model listener vetoes the write of a credit exit; nothing of the call is kept (AID-1864)
     */
    public function allocate(GroupedPayment $payment, Allocation $allocation, ?string $recordedBy = null, ?DateTimeInterface $appliedAt = null): PaymentAllocation
    {
        OutermostTransaction::assert(__METHOD__); // AID-1884: before any read

        $appliedAt ??= now();
        $amount    = $allocation->amount->toScale(2); // exact: Allocation rejects sub-cent amounts

        return DB::transaction(function () use ($payment, $allocation, $amount, $recordedBy, $appliedAt): PaymentAllocation {
            // FIRST statements: the locking reads, payment then invoice (global order).
            $locked   = $this->lockPayment((string) $payment->getKey());
            $invoices = Ledger::lockInvoices([$allocation->invoiceId]);

            if (! $locked->isPosted()) {
                throw AllocationExceedsPaymentException::forPayment((string) $locked->id, 0, $amount->unscaledValue());
            }

            Ledger::assertAllFound([$allocation->invoiceId], $invoices);
            $invoices->load('companyFiscalConfig');
            /** @var Invoice $invoice present: assertAllFound() rejects a missing id */
            $invoice = $invoices->get($allocation->invoiceId);

            Ledger::assertPayable($invoice, (string) $locked->billable_user_id, $locked->currency, Ledger::payableStatuses());
            Ledger::assertNotClaimedSale($invoice, 'allocating credit'); // AID-1825 §1a rule 2
            Ledger::assertWithinOutstanding($invoice, $amount);

            // AID-1856: the entries that can fund this allocation, decided on their LOCKED
            // rows, each with what it has left — the same set funds the allocation below.
            $funding   = $this->fundingEntries($locked);
            $available = FixedDecimal::zero(2);
            foreach ($funding as [, $remaining]) {
                $available = $available->plus($remaining);
            }

            if ($amount->isGreaterThan($available)) {
                throw AllocationExceedsPaymentException::forPayment((string) $locked->id, $available->toScale(2)->unscaledValue(), $amount->unscaledValue());
            }

            $row = Ledger::createAllocation((string) $locked->id, $invoice, $amount, $appliedAt);
            $this->consumeEntries($locked, $row, $amount, $recordedBy, $appliedAt, $funding);
            $invoice->recomputeCollectionState();

            return $row->refresh();
        });
    }

    /**
     * Void a whole payment (spec §5.5): the payment, every ACTIVE allocation
     * (`active_invoice_id = NULL`) and every ACTIVE credit entry are reversed
     * with the same audit, and every invoice those allocations touched is
     * recomputed — an invoice another live payment still covers stays
     * PARTIALLY_PAID. A second call is a stable no-op. The key stays spent.
     *
     * Locks, as the first statements of its transaction (spec §13 row 40):
     * the payment row, then the invoices of its active allocations by id
     * ascending. Those invoice ids are read BEFORE the transaction: a plain
     * read between the two locks would fix the snapshot before the invoice
     * locks, and a recomputation would miss what a concurrent writer of the
     * same invoice committed (row 40). The ids read early cannot miss a
     * DIRECT allocation (only receive() creates those, with the payment);
     * any allocation added in between is credit-funded, so it carries an
     * APPLIED exit and the reversal is refused anyway.
     *
     * Must NOT run inside any caller transaction, with or without a prior
     * read: inside one, the pre-transaction id read becomes the wrapper's
     * first consistent read and pins the snapshot BEFORE the invoice locks,
     * so the recomputation reads stale allocations (spec §13 rows 40-41).
     * A consumer calls it outside its own transaction and does its own work
     * before or after it. Guarded (row 45): above
     * `larabill.payments.base_transaction_level` (default 0; 1 under
     * RefreshDatabase) it throws before reading anything.
     *
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     * @throws InsufficientCreditException when an active entry of the payment has an active exit (APPLIED or REFUNDED): undo that first
     */
    public function reverse(GroupedPayment $payment, string $reason, ?string $reversedBy = null): GroupedPayment
    {
        OutermostTransaction::assert(__METHOD__); // before any read (spec §13.10 row 45)

        $paymentId  = (string) $payment->getKey();
        $invoiceIds = $this->activeInvoiceIds($paymentId);

        return DB::transaction(function () use ($paymentId, $invoiceIds, $reason, $reversedBy): GroupedPayment {
            // FIRST statements: the locking reads, payment then invoices (global order).
            $locked   = $this->lockPayment($paymentId);
            $invoices = Ledger::lockInvoices($invoiceIds);

            if ($locked->isReversed()) {
                return $this->loaded($locked); // stable no-op, audit fields untouched
            }

            // AID-1825 §1a rule 5: a payment funding a voucher sale is
            // reversible at stage PENDING only — the reversal releases the
            // claim and, if S2 already ran, cancels the PREPARED child so the
            // proforma returns to DRAFT. At ISSUING or BACKED it is refused.
            $claim = VoucherSaleClaim::query()->where('grouped_payment_id', $locked->id)->first();
            if ($claim !== null) {
                if ($claim->stage !== VoucherSaleStage::Pending) {
                    throw SaleStageException::reverseRefused($paymentId, (string) $claim->id, $claim->stage);
                }

                $this->releaseSaleClaim($claim, $reversedBy ?? 'reverse()');
            }

            $entryIds = $locked->creditMovements()->active()->entries()->pluck('id');
            $consumed = CreditMovement::query()
                ->active()
                ->whereIn('consumes_movement_id', $entryIds)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->first();

            if ($consumed !== null) {
                throw InsufficientCreditException::entryHasActiveExits($paymentId, (string) $consumed->consumes_movement_id);
            }

            /** @var Collection<int, PaymentAllocation> $active */
            $active = $locked->allocations()->active()->get();
            foreach ($active as $row) {
                if (! $invoices->has((string) $row->invoice_id)) {
                    throw new LogicException("Payment {$paymentId}: direct allocation {$row->id} names invoice {$row->invoice_id}, which was not locked.");
                }
            }

            $at    = now();
            $audit = ['reversed_at' => $at, 'reversed_by' => $reversedBy, 'reverse_reason' => $reason];

            CheckedWrite::orFail($locked->update(['status' => GroupedPaymentStatus::REVERSED] + $audit), 'the REVERSED payment');

            PaymentAllocation::query()
                ->whereKey($active->modelKeys())
                ->update(['status' => AllocationStatus::REVERSED->value, 'active_invoice_id' => null] + $audit);

            CreditMovement::query()->whereKey($entryIds->all())->update($audit);

            foreach ($invoices as $invoice) {
                $invoice->recomputeCollectionState();
            }

            return $this->loaded($locked);
        });
    }

    /**
     * Reverse ONE allocation (spec §5.6) and send its money back where it came
     * from. An allocation is funded either directly (no active APPLIED exit
     * points at it) or from credit (its active APPLIED exits add up to its
     * applied_amount). Credit-funded: those exits are reversed and the entries
     * they consumed get their remaining back; nothing is created. Direct: ONE
     * SURPLUS entry of applied_amount is created, linked to the allocation,
     * with `occurred_at` = the payment's paid_at (the money arrived then).
     * The row becomes REVERSED with `active_invoice_id = NULL`; the invoice is
     * recomputed. A second call is a stable no-op.
     *
     * Locks, as the first statements of its transaction (spec §13 row 40):
     * the payment row, then the invoice row. The allocation's payment and
     * invoice ids are immutable and are read BEFORE the transaction, so no
     * plain read precedes the locks; locking the pivot row first instead
     * would invert the global order against allocate() and reverse().
     *
     * Must NOT run inside any caller transaction, with or without a prior
     * read: inside one, the pre-transaction id read becomes the wrapper's
     * first consistent read and pins the snapshot BEFORE the invoice lock,
     * so the recomputation reads stale allocations (spec §13 rows 40-41).
     * A consumer calls it outside its own transaction and does its own work
     * before or after it. Guarded (row 45): above
     * `larabill.payments.base_transaction_level` (default 0; 1 under
     * RefreshDatabase) it throws before reading anything.
     *
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     * @throws ModelNotFoundException when the allocation does not exist
     * @throws LogicException when the active APPLIED exits are neither 0 nor applied_amount (a broken invariant)
     */
    public function reverseAllocation(int $allocationId, string $reason, ?string $reversedBy = null): PaymentAllocation
    {
        OutermostTransaction::assert(__METHOD__); // before any read (spec §13.10 row 45)

        $ids = PaymentAllocation::query()->whereKey($allocationId)->firstOrFail(['id', 'grouped_payment_id', 'invoice_id']);

        return DB::transaction(function () use ($allocationId, $ids, $reason, $reversedBy): PaymentAllocation {
            // FIRST statements: the locking reads, payment then invoice (global order).
            $payment  = $this->lockPayment((string) $ids->grouped_payment_id);
            $invoices = Ledger::lockInvoices([(string) $ids->invoice_id]);

            $row = PaymentAllocation::query()->whereKey($allocationId)->firstOrFail();
            if (! $row->isActive()) {
                return $row; // stable no-op, audit fields untouched
            }

            // AID-1825 §1a rule 2: the collection of a voucher sale is never
            // reversed through this path — it would mint a SURPLUS entry from
            // the money the voucher entry already represents.
            $saleClaimId = $invoices->get((string) $row->invoice_id)?->getAttribute('sale_claim_id');
            if ($saleClaimId !== null) {
                throw SaleClaimedException::allocation((int) $row->id, (string) $row->invoice_id, (string) $saleClaimId);
            }

            // (1) The funding, read BEFORE anything is touched.
            $exits  = CreditMovement::query()
                ->active()
                ->where('allocation_id', $row->id)
                ->where('kind', CreditMovementKind::APPLIED->value)
                ->get();
            $funded  = -(int) $exits->sum(fn (CreditMovement $exit): int => $exit->amount->toScale(2)->unscaledValue());
            $applied = $row->applied_amount->toScale(2)->unscaledValue();

            if ($funded !== 0 && $funded !== $applied) {
                throw new LogicException("Allocation {$row->id}: active APPLIED exits fund {$funded} of {$applied} (base-100); an allocation is funded either fully from credit or not at all.");
            }

            $at    = now();
            $audit = ['reversed_at' => $at, 'reversed_by' => $reversedBy, 'reverse_reason' => $reason];

            // (2) The row.
            PaymentAllocation::query()
                ->whereKey($row->id)
                ->update(['status' => AllocationStatus::REVERSED->value, 'active_invoice_id' => null] + $audit);

            // (3) The money goes back.
            if ($funded === $applied) {
                CreditMovement::query()->whereKey($exits->modelKeys())->update($audit);
            } else {
                CreditMovement::query()->create([
                    'billable_user_id'   => $payment->billable_user_id,
                    'currency'           => $payment->currency,
                    'kind'               => CreditMovementKind::SURPLUS,
                    'amount'             => $row->applied_amount,
                    'grouped_payment_id' => $payment->id,
                    'allocation_id'      => $row->id,
                    'recorded_by'        => $reversedBy,
                    'occurred_at'        => $payment->paid_at,
                ]);
            }

            // (4) The invoice, on its locked instance.
            $invoices->get((string) $row->invoice_id)?->recomputeCollectionState();

            return $row->refresh();
        });
    }

    /**
     * Release a PENDING voucher-sale claim on reversal (AID-1825 §1a rule 5):
     * the PREPARED child, if S2 ran, is cancelled through the sale's own
     * path (document CANCELLED, proforma back to DRAFT, re-convertible) and
     * the claim row is deleted — the proforma can be sold again under a new
     * key. Runs inside reverse()'s transaction, under the payment lock.
     */
    private function releaseSaleClaim(VoucherSaleClaim $claim, string $actor): void
    {
        $prepared = Invoice::query()->where('sale_claim_id', $claim->id)->first();

        if ($prepared !== null) {
            if ($prepared->invoice_document_status !== InvoiceDocumentStatus::PREPARED) {
                throw SaleStageException::reverseRefused((string) $claim->grouped_payment_id, (string) $claim->id, $claim->stage);
            }

            VoucherSaleClaim::withSaleAuthorization(
                (string) $claim->id,
                fn () => app(InvoiceService::class)->cancelPreparedInvoice($prepared, $actor),
            );
            Invoice::query()->whereKey($prepared->getKey())->update(['sale_claim_id' => null]);
        }

        CheckedWrite::orFail($claim->delete(), 'the released sale claim');
    }

    /** Pre-check outcome for an already-used key: same payment, or a controlled conflict. */
    private function replay(GroupedPayment $existing, ReceiveRequest $request, ReplayPolicy $policy): GroupedPayment
    {
        if ($existing->isReversed()) {
            throw IdempotencyConflictException::keySpentByReversal($request->idempotencyKey); // D2: spent key
        }

        if ($existing->isPosted() && $this->replayMatches($existing, $request, $policy)) {
            return $this->loaded($existing);
        }

        throw IdempotencyConflictException::forKey($request->idempotencyKey);
    }

    /** Lock ONE payment row — the first locking read of allocate(), reverse() and reverseAllocation(). */
    private function lockPayment(string $paymentId): GroupedPayment
    {
        return GroupedPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Distinct invoice ids of the payment's ACTIVE allocations, sorted —
     * read before reverse() opens its transaction (see reverse()).
     *
     * @return list<string>
     */
    private function activeInvoiceIds(string $paymentId): array
    {
        $ids = PaymentAllocation::query()
            ->where('grouped_payment_id', $paymentId)
            ->active()
            ->distinct()
            ->pluck('invoice_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * The entries of $payment that can fund an allocation, oldest first
     * (`occurred_at`, then `id`), each with what it has left (AID-1856, spec
     * §3.4): a plain read of the candidate ids — active entries, neither
     * voucher-backed nor advance-backed — and then
     * Ledger::lockFundingEntries(), which decides on the locked rows. Runs
     * under the payment lock, after the invoice checks.
     *
     * @return list<array{0: CreditMovement, 1: FixedDecimal}> entry, remaining
     */
    private function fundingEntries(GroupedPayment $payment): array
    {
        /** @var list<string> $candidateIds */
        $candidateIds = $payment->creditMovements()
            ->active()
            ->entries()
            ->notVoucherBacked() // AID-1825: a voucher never funds a payment
            ->notAdvanceBacked() // AID-1856: nor does an entry linked to an advance invoice
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $funding = [];
        foreach (Ledger::lockFundingEntries($candidateIds) as $entry) {
            $funding[] = [$entry, $entry->remaining()];
        }

        return $funding;
    }

    /**
     * Fund $row from $funding — the locked entries of $payment that can fund
     * an allocation, oldest first, each with what it has left
     * (fundingEntries()): one APPLIED exit per entry with something left,
     * until $amount is covered. Payer and currency are copied from the
     * payment. Runs under the payment lock, after the available-credit check
     * over the same set.
     *
     * Since 8.4 (AID-1778, spec §5.3–§5.4) each take is PLACED on its entry's
     * cent tape LOWEST-GAP-FIRST by ExitBands::segmentsFor() — under THIS
     * payment lock — and ONE exit row is created PER FILLED GAP: usually one
     * row, but a take spanning a refilled hole plus the frontier is split
     * into one row per gap, all on the same allocation and entry. On an
     * unresolvable entry (an active NULL-band exit) the rows carry
     * `band_start = NULL` and the take is NOT split (spec §5.7).
     *
     * @param  list<array{0: CreditMovement, 1: FixedDecimal}>  $funding
     */
    private function consumeEntries(GroupedPayment $payment, PaymentAllocation $row, FixedDecimal $amount, ?string $recordedBy, DateTimeInterface $at, array $funding): void
    {
        $left = $amount;

        foreach ($funding as [$entry, $remaining]) {
            if (! $left->isPositive()) {
                break;
            }

            if (! $remaining->isPositive()) {
                continue;
            }

            $take = $remaining->isLessThan($left) ? $remaining : $left;

            $segments = ExitBands::segmentsFor($entry, $take->toScale(2)->unscaledValue());

            if ($segments === null) {
                Ledger::createExit([
                    'billable_user_id'     => $payment->billable_user_id,
                    'currency'             => $payment->currency,
                    'kind'                 => CreditMovementKind::APPLIED,
                    'amount'               => $take->negated(),
                    'grouped_payment_id'   => $payment->id,
                    'allocation_id'        => $row->id,
                    'consumes_movement_id' => $entry->id,
                    'band_start'           => null,
                    'recorded_by'          => $recordedBy,
                    'occurred_at'          => $at,
                ]);
            } else {
                foreach ($segments as [$start, $length]) {
                    Ledger::createExit([
                        'billable_user_id'     => $payment->billable_user_id,
                        'currency'             => $payment->currency,
                        'kind'                 => CreditMovementKind::APPLIED,
                        'amount'               => FixedDecimal::ofUnscaled(-$length, 2),
                        'grouped_payment_id'   => $payment->id,
                        'allocation_id'        => $row->id,
                        'consumes_movement_id' => $entry->id,
                        'band_start'           => $start,
                        'recorded_by'          => $recordedBy,
                        'occurred_at'          => $at,
                    ]);
                }
            }

            $left = $left->minus($take);
        }

        if ($left->isPositive()) {
            throw new LogicException("Payment {$payment->id}: entries left {$left->toScale(2)->unscaledValue()} (base-100) unfunded after the availableCredit() check.");
        }
    }

    /**
     * ONE entry of the requested kind for what the payment did not allocate,
     * with the payer and currency copied from the payment; none at zero.
     */
    private function recordRemainder(GroupedPayment $payment, ReceiveRequest $request): void
    {
        $remainder = $request->amount->minus($request->allocatedAmount());

        if (! $remainder->isPositive()) {
            return;
        }

        CreditMovement::query()->create([
            'billable_user_id'   => $payment->billable_user_id,
            'currency'           => $payment->currency,
            'kind'               => $request->creditKind,
            'amount'             => $remainder,
            'grouped_payment_id' => $payment->id,
            'recorded_by'        => $request->recordedBy,
            'occurred_at'        => $request->receivedAt,
        ]);
    }

    /**
     * Applied cents per invoice id over the DIRECT allocations of $payment —
     * rows no APPLIED exit points at, active or reversed — sorted by id.
     *
     * @return array<string, int>
     */
    private function directAllocationMap(GroupedPayment $payment): array
    {
        $rows = DB::table('grouped_payment_invoice')
            ->where('grouped_payment_id', $payment->id)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('credit_movements')
                    ->whereColumn('credit_movements.allocation_id', 'grouped_payment_invoice.id')
                    ->where('credit_movements.kind', CreditMovementKind::APPLIED->value);
            })
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(applied_amount) AS cents')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->invoice_id] = (int) $row->cents;
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    private function loaded(GroupedPayment $payment): GroupedPayment
    {
        return $payment->load(['invoices', 'allocations', 'creditMovements']);
    }

    private function isIdempotencyKeyViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation; message names the column/index.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'idempotency_key');
    }
}
