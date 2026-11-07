<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\InsufficientCreditException;
use AichaDigital\Larabill\Exceptions\PaymentOutsideTransactionRequiredException;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Services\Payments\ExitBands;
use AichaDigital\Larabill\Services\Payments\Ledger;
use AichaDigital\Larabill\Services\Payments\OutermostTransaction;
use AichaDigital\Larabill\Support\DocumentCurrency;
use AichaDigital\Larabill\ValueObjects\CreditBreakdown;
use AichaDigital\Larabill\ValueObjects\CreditEntryRemainder;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A customer's credit balance per currency (AID-962, spec §5.7): the sum of
 * the ACTIVE credit movements of the customer in that currency — entries
 * (SURPLUS, TOP_UP) minus exits (APPLIED, REFUNDED) — across ALL of the
 * customer's payments. Entries are consumed FIFO by `occurred_at`, then `id`
 * (the date the money arrived, not the order it was typed in, §4.2).
 *
 * Two faces. The READ face is bound to a customer and a currency with
 * for(); movements(), balance(), outstanding() and position() throw a
 * LogicException on an unbound instance. The WRITE face (apply(), refund())
 * takes the customer explicitly and never reads the binding, so
 * `app(CustomerCredit::class)->apply(...)` works unbound.
 *
 * Since 9.4 (AID-1856) the two exits differ in what they may take: apply()
 * spends only entries that can fund a payment — an entry linked to an
 * advance invoice (`advance_invoice_id`) is left alone, like a voucher's —
 * while refund() keeps consuming advance-backed entries, the published
 * refund of an advance and its rectificative (AID-1778).
 *
 * Locking (spec §5 intro, §5.4, §13.10 rows 40-41). apply() and refund()
 * read, OUTSIDE their transaction (autocommit, fixes no snapshot), the ids of
 * the POSTED payments of the customer and currency that hold an active
 * entry (every currency of the customer when apply() targets an invoice with
 * no fiscal currency: the currency is then decided under the locks). Their transaction then opens with the locking read of ALL those
 * payments by id ascending, then (apply() only) the invoice and, after its
 * payability checks, a row lock on each candidate entry
 * (Ledger::lockFundingEntries(): whether an entry is linked to an advance is
 * decided on the locked row, so a concurrent linkAdvanceInvoice() either
 * landed before and the entry is left out, or waits for this call); every
 * remainder and the balance are read after the locks, never trusted from before. Only
 * entries of the locked payments are consumed: an entry born in between from
 * a NEW payment (receive()) is not seen in this run — it can leave credit
 * unused, never over-consume it (§5.4). Entries of a locked payment are only
 * ever created under that payment's lock (reverseAllocation()), so the
 * locked set covers every entry the run may touch; a consumed entry outside
 * it would abort with a LogicException (nothing is written under a missing
 * lock). Like PaymentService::reverse(), the write methods must NOT run
 * inside a caller's transaction: there the pre-transaction read would become
 * the caller's first consistent read and pin the snapshot before the locks,
 * and two wrapped runs could consume the same remainder twice. Above
 * `larabill.payments.base_transaction_level` they throw
 * PaymentOutsideTransactionRequiredException before reading anything.
 *
 * @api Supported public surface (AID-962; see docs/api-surface.md).
 */
final class CustomerCredit
{
    private ?string $billableUserId = null;

    private ?string $currency = null;

    /** A read object bound to $billableUserId and $currency. */
    public static function for(string $billableUserId, string $currency): self
    {
        $credit                 = new self;
        $credit->billableUserId = $billableUserId;
        $credit->currency       = $currency;

        return $credit;
    }

    /**
     * The ACTIVE movements (entries and exits) of the bound customer and
     * currency, oldest first (`occurred_at`, then `id`).
     *
     * @return Collection<int, CreditMovement>
     *
     * @throws LogicException on an unbound instance
     */
    public function movements(): Collection
    {
        return $this->boundMovements()->orderBy('occurred_at')->orderBy('id')->get();
    }

    /**
     * Σ of the ACTIVE movements of the bound customer and currency. Never
     * stored: read every time.
     *
     * @throws LogicException on an unbound instance
     */
    public function balance(): FixedDecimal
    {
        return FixedDecimal::ofUnscaled((int) $this->boundMovements()->sum('amount'), 2);
    }

    /**
     * Σ amountOutstanding() of the OPEN invoices of the bound customer in the
     * bound currency: issued (document axis), not proforma, collection status
     * in {SENT, OVERDUE, PENDING, PARTIALLY_PAID}, and a fiscal config whose
     * currency is the bound one or is missing (the D3 parity of
     * register()/receive(): an invoice with no fiscal currency counts in
     * every currency).
     *
     * Rectificatives count with their sign. A rectificative (linked by
     * `rectifies_invoice_id`, ADR-014) is stored with a NEGATIVE
     * `total_amount`, is never payable (§5.1.3) and so has no allocations:
     * its amountOutstanding() is that negative total, which nets the debt.
     * No branch on the kind of document: the sign lives in the stored total.
     *
     * @throws LogicException on an unbound instance
     */
    public function outstanding(): FixedDecimal
    {
        [$billableUserId, $currency] = $this->bound();

        $invoices = Invoice::query()
            ->where('billable_user_id', $billableUserId)
            ->where('invoice_document_status', InvoiceDocumentStatus::ISSUED->value)
            ->where('serie', '!=', InvoiceSerieType::PROFORMA->value)
            ->whereIn('status', array_map(fn ($status): int => $status->value, Ledger::payableStatuses()))
            ->with('companyFiscalConfig')
            ->orderBy('id')
            ->get();

        $total = FixedDecimal::zero(2);
        foreach ($invoices as $invoice) {
            $invoiceCurrency = DocumentCurrency::of($invoice);
            if ($invoiceCurrency !== null && $invoiceCurrency !== $currency) {
                continue;
            }

            $total = $total->plus($invoice->amountOutstanding());
        }

        return $total->toScale(2);
    }

    /**
     * balance() − outstanding(): positive when the customer is owed money.
     *
     * @throws LogicException on an unbound instance
     */
    public function position(): FixedDecimal
    {
        return $this->balance()->minus($this->outstanding());
    }

    /**
     * The live balance of the bound customer and currency split by the
     * origin of each entry (AID-1817): consumable (no advance invoice) and
     * advance-backed (`advance_invoice_id` set, whatever the advance's
     * document state). Each entry counts its remainder — its amount plus its
     * ACTIVE exits, the same arithmetic as CreditMovement::remaining() — so
     * an entry linked after being partly consumed counts only what is left,
     * and refunds weigh FIFO exactly as refund() placed them. The two parts
     * add up to balance().
     *
     * Two plain reads (the entries, then their exits aggregated in one
     * grouped query); no lock, no write. Like balance(), it is a snapshot,
     * not a reservation: a concurrent apply() may consume what it shows.
     *
     * @throws LogicException on an unbound instance
     */
    public function breakdown(): CreditBreakdown
    {
        $entries = $this->boundMovements()->entries()->orderBy('occurred_at')->orderBy('id')->get();

        /** @var array<string, int> $consumed */
        $consumed = $entries->isEmpty() ? [] : CreditMovement::query()
            ->whereIn('consumes_movement_id', $entries->modelKeys())
            ->active()
            ->groupBy('consumes_movement_id')
            ->selectRaw('consumes_movement_id, SUM(amount) AS consumed')
            ->toBase()   // a raw aggregate row, not a CreditMovement: no cast on `consumed`
            ->pluck('consumed', 'consumes_movement_id')
            ->map(fn ($sum): int => (int) $sum)
            ->all();

        $consumable    = FixedDecimal::zero(2);
        $advanceBacked = FixedDecimal::zero(2);
        $voucherBacked = FixedDecimal::zero(2);
        $live          = [];
        foreach ($entries as $entry) {
            $remaining = $entry->amount->plus(FixedDecimal::ofUnscaled($consumed[(string) $entry->id] ?? 0, 2))->toScale(2);

            if ($entry->sale_invoice_id !== null) {
                $voucherBacked = $voucherBacked->plus($remaining); // AID-1825: the voucher's own part
            } elseif ($entry->advance_invoice_id === null) {
                $consumable = $consumable->plus($remaining);
            } else {
                $advanceBacked = $advanceBacked->plus($remaining);
            }

            if ($remaining->isPositive()) {
                $live[] = new CreditEntryRemainder((string) $entry->id, $remaining, $entry->advance_invoice_id, $entry->sale_invoice_id);
            }
        }

        return new CreditBreakdown($consumable->toScale(2), $advanceBacked->toScale(2), $live, $voucherBacked->toScale(2));
    }

    /**
     * Apply $amount of $billableUserId's credit to ONE issued invoice (spec
     * §5.7). The currency is the invoice's fiscal currency; an invoice with
     * no fiscal currency (D3 parity) takes the ONLY currency in which the
     * customer holds a positive balance, decided under the locks AFTER the
     * payability checks (spec §13.10 row 42): none → noCreditForCustomer,
     * several → currencyAmbiguous. A currency mismatch between credit and
     * invoice surfaces as insufficient credit in the invoice's currency.
     *
     * Only credit that can fund a payment is spent (AID-1856): entries
     * linked to an advance invoice are never taken — their VAT was declared
     * on the advance — and they are not a currency hint either; an amount
     * above the consumable part (CustomerCredit::breakdown()) is refused as
     * insufficient credit even when balance() covers it.
     *
     * Entries are consumed FIFO across all of the customer's payments and
     * grouped BY PAYMENT: one allocation per payment spent (the sum consumed
     * from that payment's entries, `grouped_payment_id` = that payment) and
     * one APPLIED exit PER FILLED BAND GAP of each entry consumed, pointing
     * at its payment's allocation — usually one exit per entry, but a take
     * spanning a refilled hole plus the frontier is split into one exit row
     * per gap, all sharing the allocation (AID-1778, spec §5.3–§5.4) — so
     * every allocation stays funded from its own payment's
     * entries, which is what reverseAllocation() reads. `$appliedAt`
     * (default now) is the allocations' `applied_at` and the exits'
     * `occurred_at`; `$recordedBy` goes on the exits. The locked invoice is
     * recomputed once.
     *
     * Order of the checks (§5.7): amount; invoice payable by this customer
     * (§5.1.3); amount ≤ balance (read under the locks); amount ≤
     * amountOutstanding(). Locks: see the class docblock. Must NOT run inside
     * a caller's transaction; guarded by `larabill.payments.base_transaction_level`.
     *
     * @return Collection<int, PaymentAllocation> in the FIFO order of their payments
     *
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     * @throws GroupedPaymentValidationException on a non-positive or sub-cent amount, an invoice that is not payable, or (legacy issued rows with no snapshot currency only) a referenced config whose currency changed during the call
     * @throws InsufficientCreditException when the credit that can fund a payment cannot cover the amount (forBalance), or the invoice has no fiscal currency and the customer holds such credit in no currency (noCreditForCustomer) or in several (currencyAmbiguous)
     * @throws AllocationExceedsInvoiceException when the amount exceeds the invoice outstanding
     * @throws LogicException when a model listener vetoes the write of a credit exit; nothing of the call is kept (AID-1864)
     */
    public function apply(string $billableUserId, Invoice $invoice, FixedDecimal $amount, ?string $recordedBy = null, ?DateTimeInterface $appliedAt = null): Collection
    {
        OutermostTransaction::assert(__METHOD__); // before any read (spec §13.10 row 45)

        $amount = self::validAmount($amount);
        $appliedAt ??= now();
        $invoiceId = (string) $invoice->getKey();

        // Plain reads OUTSIDE the transaction: which payments to lock. With no
        // fiscal currency on the invoice, the candidates of EVERY currency of
        // the customer; the currency is decided under the locks.
        $readCurrency = self::documentCurrency($invoiceId);
        $paymentIds   = self::sourcePaymentIds($billableUserId, $readCurrency, fundingOnly: true);

        return DB::transaction(function () use ($billableUserId, $invoiceId, $amount, $readCurrency, $paymentIds, $recordedBy, $appliedAt): Collection {
            // FIRST statements: the locking reads, payments then invoice (global order).
            $locked   = self::lockPayments($paymentIds);
            $invoices = Ledger::lockInvoices([$invoiceId]);

            Ledger::assertAllFound([$invoiceId], $invoices);
            $invoices->load('companyFiscalConfig');
            /** @var Invoice $target present: assertAllFound() rejects a missing id */
            $target = $invoices->get($invoiceId);

            // 9.3.1: the document's currency, decided from the LOCKED row — the frozen
            // snapshot once issued (immutable, cannot move under us), else the config.
            $fiscal = DocumentCurrency::of($target);
            // With no fiscal currency the §5.1.3 currency check does not apply (D3); any value passes it.
            Ledger::assertPayable($target, $billableUserId, $fiscal ?? '', Ledger::payableStatuses());
            Ledger::assertNotClaimedSale($target, 'applying credit'); // AID-1825 §1a rule 2: legacy credit is never applied TO a sale invoice

            // The payments were chosen outside the transaction for $readCurrency. On the
            // legacy fallback (no snapshot currency) the config may have moved since:
            // refuse rather than plan over payments locked for another currency.
            if ($fiscal !== $readCurrency) {
                throw GroupedPaymentValidationException::currencyMismatch($invoiceId, (string) $readCurrency, (string) $fiscal);
            }

            // AID-1856: the entries that can fund a payment, decided on their LOCKED rows —
            // once, after the payability checks, so a refused invoice locks no entry. The
            // inference and the plan both read this one set.
            $funding  = self::fundingEntries($billableUserId, $fiscal, $locked);
            $currency = $fiscal ?? self::inferCurrency($billableUserId, $funding);
            $plan     = self::plan($billableUserId, $currency, $locked, $amount, $funding->filter(fn (CreditMovement $entry): bool => $entry->currency === $currency));
            Ledger::assertWithinOutstanding($target, $amount);

            /** @var Collection<int, PaymentAllocation> $rows */
            $rows = new Collection;
            foreach (self::byPayment($plan) as $paymentId => $takes) {
                $sum = FixedDecimal::zero(2);
                foreach ($takes as [, $take]) {
                    $sum = $sum->plus($take);
                }

                $row = Ledger::createAllocation($paymentId, $target, $sum, $appliedAt);
                foreach ($takes as [$entry, $take]) {
                    self::recordExit($entry, CreditMovementKind::APPLIED, $take, $appliedAt, $recordedBy, allocationId: $row->id);
                }
                $rows->push($row);
            }

            $target->recomputeCollectionState();

            return $rows->map(fn (PaymentAllocation $row): PaymentAllocation => $row->refresh());
        });
    }

    /**
     * Record $amount of $billableUserId's $currency credit leaving as a
     * refund (spec §5.7): one REFUNDED exit PER FILLED BAND GAP of each entry
     * consumed FIFO, with `occurred_at = $refundedAt`, `method`, `reference`
     * and `recorded_by`. Entries linked to an advance invoice ARE consumed
     * here, in the same FIFO order as the rest (AID-1856 leaves this path
     * untouched: each such exit feeds AdvanceInvoiceBuilder::rectificativeLines()).
     * Since 8.4 (AID-1778, spec §5.4) ONE consumed entry
     * may carry SEVERAL REFUNDED rows — one per filled gap — when the take is
     * split across the free tape; every row of the split is returned in the
     * collection (and every row of a split shares the entry's
     * `consumes_movement_id`). The package records the money leaving; it does
     * not move it. Locks: all source payments by id ascending (no invoice).
     * Must NOT run inside a caller's transaction; guarded by
     * `larabill.payments.base_transaction_level`.
     *
     * @return Collection<int, CreditMovement> the exits, in FIFO order
     *
     * @throws PaymentOutsideTransactionRequiredException when called inside a caller's transaction
     * @throws GroupedPaymentValidationException on a non-positive or sub-cent amount
     * @throws InsufficientCreditException when the balance cannot cover the amount
     * @throws LogicException when a model listener vetoes the write of a credit exit; nothing of the call is kept (AID-1864)
     */
    public function refund(string $billableUserId, FixedDecimal $amount, string $currency, DateTimeInterface $refundedAt, PaymentMethod $method, ?string $reference = null, ?string $recordedBy = null): Collection
    {
        OutermostTransaction::assert(__METHOD__); // before any read (spec §13.10 row 45)

        $amount     = self::validAmount($amount);
        $paymentIds = self::sourcePaymentIds($billableUserId, $currency); // plain read OUTSIDE the transaction

        return DB::transaction(function () use ($billableUserId, $amount, $currency, $paymentIds, $refundedAt, $method, $reference, $recordedBy): Collection {
            // FIRST statement: the locking read of every source payment.
            $locked = self::lockPayments($paymentIds);

            /** @var Collection<int, CreditMovement> $exits */
            $exits = new Collection;
            foreach (self::plan($billableUserId, $currency, $locked, $amount, self::refundableEntries($billableUserId, $currency, $locked)) as [$entry, $take]) {
                $exits = $exits->merge(self::recordExit($entry, CreditMovementKind::REFUNDED, $take, $refundedAt, $recordedBy, method: $method, reference: $reference));
            }

            return $exits;
        });
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws LogicException on an unbound instance
     */
    private function bound(): array
    {
        if ($this->billableUserId === null || $this->currency === null) {
            throw new LogicException('CustomerCredit is not bound to a customer and currency: build it with CustomerCredit::for().');
        }

        return [$this->billableUserId, $this->currency];
    }

    /** @return Builder<CreditMovement> */
    private function boundMovements(): Builder
    {
        [$billableUserId, $currency] = $this->bound();

        return CreditMovement::query()
            ->where('billable_user_id', $billableUserId)
            ->where('currency', $currency)
            ->active();
    }

    /** The document's currency (DocumentCurrency::of()), a plain read OUTSIDE the transaction (null when it has none or does not exist). */
    private static function documentCurrency(string $invoiceId): ?string
    {
        $invoice = Invoice::query()->with('companyFiscalConfig')->find($invoiceId);

        return $invoice === null ? null : DocumentCurrency::of($invoice);
    }

    /**
     * The currency of an apply() on an invoice with no fiscal currency,
     * decided UNDER the locks: the only currency in which the entries that
     * can fund a payment leave a positive balance (spec §13.10 row 42).
     * Advance-backed and voucher-backed entries are not in $funding, so
     * neither is a currency hint (AID-1856, AID-1825).
     *
     * @param  Collection<int, CreditMovement>  $funding  the locked funding entries of every currency
     *
     * @throws InsufficientCreditException noCreditForCustomer / currencyAmbiguous
     */
    private static function inferCurrency(string $billableUserId, Collection $funding): string
    {
        $balances = [];
        foreach ($funding as $entry) {
            $balances[$entry->currency] = ($balances[$entry->currency] ?? FixedDecimal::zero(2))->plus($entry->remaining());
        }

        $currencies = array_keys(array_filter($balances, fn (FixedDecimal $balance): bool => $balance->isPositive()));
        sort($currencies, SORT_STRING);

        return match (count($currencies)) {
            0       => throw InsufficientCreditException::noCreditForCustomer($billableUserId),
            1       => $currencies[0],
            default => throw InsufficientCreditException::currencyAmbiguous($billableUserId, $currencies),
        };
    }

    /**
     * Ids of the POSTED payments of the customer (in $currency, or in any
     * currency when null) holding at least one ACTIVE entry, sorted — read
     * OUTSIDE the transaction (§5.4 step 1). With $fundingOnly (apply()) a
     * payment whose only entries are linked to an advance invoice is not a
     * source payment (AID-1856); refund() keeps counting them.
     *
     * @return list<string>
     */
    private static function sourcePaymentIds(string $billableUserId, ?string $currency, bool $fundingOnly = false): array
    {
        $ids = CreditMovement::query()
            ->where('billable_user_id', $billableUserId)
            ->when($currency !== null, fn (Builder $query) => $query->where('currency', $currency))
            ->active()
            ->entries()
            ->notVoucherBacked() // AID-1825: a voucher-only payment is never a source payment
            ->when($fundingOnly, fn (Builder $query) => $query->notAdvanceBacked())
            ->whereIn('grouped_payment_id', GroupedPayment::query()->select('id')->where('status', GroupedPaymentStatus::POSTED->value))
            ->distinct()
            ->pluck('grouped_payment_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Lock ALL the source payments by id ascending (§5.4 step 2) and key the
     * POSTED ones by id. A payment reversed after the id read has its entries
     * reversed too, so dropping it drops nothing it could still fund.
     *
     * @param  list<string>  $paymentIds
     * @return Collection<string, GroupedPayment>
     */
    private static function lockPayments(array $paymentIds): Collection
    {
        if ($paymentIds === []) {
            /** @var Collection<string, GroupedPayment> $none */
            $none = new Collection;

            return $none;
        }

        /** @var Collection<string, GroupedPayment> $locked */
        $locked = GroupedPayment::query()
            ->whereIn('id', $paymentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (GroupedPayment $payment): bool => $payment->isPosted())
            ->keyBy(fn (GroupedPayment $payment): string => (string) $payment->id);

        return $locked;
    }

    /**
     * The entries apply() may spend, FIFO (`occurred_at`, then `id`), read
     * UNDER the payment locks (AID-1856, spec §3.3): a plain read of the
     * candidate ids — the active entries of the locked payments that are
     * neither voucher-backed nor advance-backed, in $currency or in every
     * currency when null — and then Ledger::lockFundingEntries(), which
     * decides on the locked rows. The candidate rows serve only to obtain
     * ids; the instances returned are the locked ones.
     *
     * @param  Collection<string, GroupedPayment>  $locked
     * @return Collection<int, CreditMovement>
     */
    private static function fundingEntries(string $billableUserId, ?string $currency, Collection $locked): Collection
    {
        if ($locked->isEmpty()) {
            /** @var Collection<int, CreditMovement> $none */
            $none = new Collection;

            return $none;
        }

        /** @var list<string> $candidateIds */
        $candidateIds = CreditMovement::query()
            ->where('billable_user_id', $billableUserId)
            ->when($currency !== null, fn (Builder $query) => $query->where('currency', $currency))
            ->active()
            ->entries()
            ->notVoucherBacked()
            ->notAdvanceBacked()
            ->whereIn('grouped_payment_id', $locked->keys()->all())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return Ledger::lockFundingEntries($candidateIds);
    }

    /**
     * The entries refund() consumes, FIFO (`occurred_at`, then `id`), read
     * UNDER the payment locks (§5.4 step 3): the active entries of the locked
     * payments, advance-backed ones INCLUDED — the published refund of an
     * advance and its rectificative (AID-1778) — and voucher-backed ones
     * excluded. No entry row is locked on this path (AID-1856, spec §3.6).
     *
     * @param  Collection<string, GroupedPayment>  $locked
     * @return Collection<int, CreditMovement>
     */
    private static function refundableEntries(string $billableUserId, string $currency, Collection $locked): Collection
    {
        if ($locked->isEmpty()) {
            /** @var Collection<int, CreditMovement> $none */
            $none = new Collection;

            return $none;
        }

        return CreditMovement::query()
            ->where('billable_user_id', $billableUserId)
            ->where('currency', $currency)
            ->active()
            ->entries()
            ->notVoucherBacked() // AID-1825 §1 item 4: never a payment, never a refund
            ->whereIn('grouped_payment_id', $locked->keys()->all())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The FIFO consumption plan over $entries (already selected, in FIFO
     * order, under the payment locks — §5.4 step 3): each entry with what is
     * taken from it, until $amount is covered. The selection is the caller's
     * (fundingEntries() for apply(), refundableEntries() for refund()); the
     * arithmetic is shared.
     *
     * @param  Collection<string, GroupedPayment>  $locked
     * @param  Collection<int, CreditMovement>  $entries
     * @return list<array{0: CreditMovement, 1: FixedDecimal}>
     *
     * @throws InsufficientCreditException when the entries cannot cover $amount
     * @throws LogicException when an entry to consume belongs to a payment that is not locked
     */
    private static function plan(string $billableUserId, string $currency, Collection $locked, FixedDecimal $amount, Collection $entries): array
    {
        $remaining = [];
        $balance   = FixedDecimal::zero(2);
        foreach ($entries as $entry) {
            $remaining[(string) $entry->id] = $entry->remaining();
            $balance                        = $balance->plus($remaining[(string) $entry->id]);
        }

        if ($amount->isGreaterThan($balance)) {
            throw InsufficientCreditException::forBalance($billableUserId, $currency, $balance->toScale(2)->unscaledValue(), $amount->unscaledValue());
        }

        $plan = [];
        $left = $amount;
        foreach ($entries as $entry) {
            if (! $left->isPositive()) {
                break;
            }

            $available = $remaining[(string) $entry->id];
            if (! $available->isPositive()) {
                continue;
            }

            if (! $locked->has((string) $entry->grouped_payment_id)) {
                throw new LogicException("Credit entry {$entry->id} belongs to payment {$entry->grouped_payment_id}, which was not locked.");
            }

            $take   = $available->isLessThan($left) ? $available : $left;
            $plan[] = [$entry, $take->toScale(2)];
            $left   = $left->minus($take);
        }

        return $plan;
    }

    /**
     * The plan grouped by the entries' payment, payments in the order they
     * are first consumed.
     *
     * @param  list<array{0: CreditMovement, 1: FixedDecimal}>  $plan
     * @return array<string, list<array{0: CreditMovement, 1: FixedDecimal}>>
     */
    private static function byPayment(array $plan): array
    {
        $groups = [];
        foreach ($plan as $step) {
            $groups[(string) $step[0]->grouped_payment_id][] = $step;
        }

        return $groups;
    }

    /**
     * The exit rows consuming $take of $entry; customer, currency and payment
     * are copied from the entry it consumes (§5.1 step 4). APPLIED exits carry
     * their allocation; REFUNDED exits carry the method and reference.
     *
     * Since 8.4 (AID-1778) the take is PLACED on the entry's cent tape
     * LOWEST-GAP-FIRST by ExitBands::segmentsFor() — under the source-payment
     * lock this transaction already holds — and ONE row is created PER FILLED
     * GAP: usually one row, but a take spanning a refilled hole plus the
     * frontier is split into one row per gap, all sharing the allocation and
     * every other copied attribute (spec §5.3–§5.4). On an unresolvable entry
     * (an active NULL-band exit) the rows carry `band_start = NULL` and the
     * take is NOT split (spec §5.7).
     *
     * @return Collection<int, CreditMovement> the created exit rows, in tape position order
     */
    private static function recordExit(
        CreditMovement $entry,
        CreditMovementKind $kind,
        FixedDecimal $take,
        DateTimeInterface $at,
        ?string $recordedBy,
        ?int $allocationId = null,
        ?PaymentMethod $method = null,
        ?string $reference = null,
    ): Collection {
        $segments   = ExitBands::segmentsFor($entry, $take->toScale(2)->unscaledValue());
        $attributes = [
            'billable_user_id'     => $entry->billable_user_id,
            'currency'             => $entry->currency,
            'kind'                 => $kind,
            'grouped_payment_id'   => $entry->grouped_payment_id,
            'consumes_movement_id' => $entry->id,
            'allocation_id'        => $allocationId,
            'method'               => $method,
            'reference'            => $reference,
            'recorded_by'          => $recordedBy,
            'occurred_at'          => $at,
        ];

        /** @var Collection<int, CreditMovement> $rows */
        $rows = new Collection;

        if ($segments === null) {
            $rows->push(Ledger::createExit($attributes + [
                'amount'     => $take->negated(),
                'band_start' => null,
            ]));

            return $rows;
        }

        foreach ($segments as [$start, $length]) {
            $rows->push(Ledger::createExit($attributes + [
                'amount'     => FixedDecimal::ofUnscaled(-$length, 2),
                'band_start' => $start,
            ]));
        }

        return $rows;
    }

    /**
     * A positive, cent-exact amount, at scale 2 (§4.5).
     *
     * @throws GroupedPaymentValidationException
     */
    private static function validAmount(FixedDecimal $amount): FixedDecimal
    {
        if (! $amount->isPositive()) {
            throw GroupedPaymentValidationException::nonPositiveAmount($amount->unscaledValue());
        }

        if ($amount->toScale(2)->compareTo($amount) !== 0) {
            throw GroupedPaymentValidationException::subCentAmount($amount->toDecimalString());
        }

        return $amount->toScale(2);
    }
}
