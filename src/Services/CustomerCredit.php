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
 * Locking (spec §5 intro, §5.4, §13.10 rows 40-41). apply() and refund()
 * read, OUTSIDE their transaction (autocommit, fixes no snapshot), the ids of
 * the POSTED payments of the customer and currency that hold an active
 * entry (every currency of the customer when apply() targets an invoice with
 * no fiscal currency: the currency is then decided under the locks). Their transaction then opens with the locking read of ALL those
 * payments by id ascending, then (apply() only) the invoice; every remainder
 * and the balance are read after the locks, never trusted from before. Only
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
            $invoiceCurrency = $invoice->companyFiscalConfig?->currency;
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
        $live          = [];
        foreach ($entries as $entry) {
            $remaining = $entry->amount->plus(FixedDecimal::ofUnscaled($consumed[(string) $entry->id] ?? 0, 2))->toScale(2);

            if ($entry->advance_invoice_id === null) {
                $consumable = $consumable->plus($remaining);
            } else {
                $advanceBacked = $advanceBacked->plus($remaining);
            }

            if ($remaining->isPositive()) {
                $live[] = new CreditEntryRemainder((string) $entry->id, $remaining, $entry->advance_invoice_id);
            }
        }

        return new CreditBreakdown($consumable->toScale(2), $advanceBacked->toScale(2), $live);
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
     * @throws GroupedPaymentValidationException on a non-positive or sub-cent amount, or an invoice that is not payable
     * @throws InsufficientCreditException when the balance cannot cover the amount (forBalance), or the invoice has no fiscal currency and the customer holds a positive balance in no currency (noCreditForCustomer) or in several (currencyAmbiguous)
     * @throws AllocationExceedsInvoiceException when the amount exceeds the invoice outstanding
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
        $paymentIds = self::sourcePaymentIds($billableUserId, self::fiscalCurrency($invoiceId));

        return DB::transaction(function () use ($billableUserId, $invoiceId, $amount, $paymentIds, $recordedBy, $appliedAt): Collection {
            // FIRST statements: the locking reads, payments then invoice (global order).
            $locked   = self::lockPayments($paymentIds);
            $invoices = Ledger::lockInvoices([$invoiceId]);

            Ledger::assertAllFound([$invoiceId], $invoices);
            $invoices->load('companyFiscalConfig');
            /** @var Invoice $target present: assertAllFound() rejects a missing id */
            $target = $invoices->get($invoiceId);

            $fiscal = $target->companyFiscalConfig?->currency;
            // With no fiscal currency the §5.1.3 currency check does not apply (D3); any value passes it.
            Ledger::assertPayable($target, $billableUserId, $fiscal ?? '', Ledger::payableStatuses());

            $currency = $fiscal ?? self::inferCurrency($billableUserId, $locked);
            $plan     = self::plan($billableUserId, $currency, $locked, $amount);
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
     * and `recorded_by`. Since 8.4 (AID-1778, spec §5.4) ONE consumed entry
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
            foreach (self::plan($billableUserId, $currency, $locked, $amount) as [$entry, $take]) {
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

    /** The fiscal currency of the invoice, a plain read OUTSIDE the transaction (null when it has none or does not exist). */
    private static function fiscalCurrency(string $invoiceId): ?string
    {
        return Invoice::query()->with('companyFiscalConfig')->find($invoiceId)?->companyFiscalConfig?->currency;
    }

    /**
     * The currency of an apply() on an invoice with no fiscal currency, read
     * UNDER the payment locks: the only currency in which the active entries
     * of the locked payments leave a positive balance (spec §13.10 row 42).
     *
     * @param  Collection<string, GroupedPayment>  $locked
     *
     * @throws InsufficientCreditException noCreditForCustomer / currencyAmbiguous
     */
    private static function inferCurrency(string $billableUserId, Collection $locked): string
    {
        $balances = [];
        if ($locked->isNotEmpty()) {
            $entries = CreditMovement::query()
                ->where('billable_user_id', $billableUserId)
                ->active()
                ->entries()
                ->whereIn('grouped_payment_id', $locked->keys()->all())
                ->get();

            foreach ($entries as $entry) {
                $balances[$entry->currency] = ($balances[$entry->currency] ?? FixedDecimal::zero(2))->plus($entry->remaining());
            }
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
     * OUTSIDE the transaction (§5.4 step 1).
     *
     * @return list<string>
     */
    private static function sourcePaymentIds(string $billableUserId, ?string $currency): array
    {
        $ids = CreditMovement::query()
            ->where('billable_user_id', $billableUserId)
            ->when($currency !== null, fn (Builder $query) => $query->where('currency', $currency))
            ->active()
            ->entries()
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
     * The FIFO consumption plan, read UNDER the payment locks (§5.4 step 3):
     * the active entries of the locked payments by `occurred_at`, then `id`,
     * each with what is taken from it, until $amount is covered.
     *
     * @param  Collection<string, GroupedPayment>  $locked
     * @return list<array{0: CreditMovement, 1: FixedDecimal}>
     *
     * @throws InsufficientCreditException when the locked entries cannot cover $amount
     * @throws LogicException when an entry to consume belongs to a payment that is not locked
     */
    private static function plan(string $billableUserId, string $currency, Collection $locked, FixedDecimal $amount): array
    {
        $entries = $locked->isEmpty() ? new Collection : CreditMovement::query()
            ->where('billable_user_id', $billableUserId)
            ->where('currency', $currency)
            ->active()
            ->entries()
            ->whereIn('grouped_payment_id', $locked->keys()->all())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

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
            $rows->push(CreditMovement::query()->create($attributes + [
                'amount'     => $take->negated(),
                'band_start' => null,
            ]));

            return $rows;
        }

        foreach ($segments as [$start, $length]) {
            $rows->push(CreditMovement::query()->create($attributes + [
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
