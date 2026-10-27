<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\Payments\Ledger;
use AichaDigital\Larabill\Services\Payments\ReceiveRequest;
use AichaDigital\Larabill\Services\Payments\ReplayPolicy;
use AichaDigital\Larabill\ValueObjects\Allocation;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class GroupedPaymentService
{
    /**
     * The 8.x card path (AID-961), kept verbatim for its callers: exact amount,
     * one allocation per invoice. Since 8.3 (AID-962) it resolves a replayed
     * key BEFORE delegating (legacy comparison: payer, currency, amount,
     * distinct invoice ids) and creates through PaymentService::process()
     * with ReplayPolicy::Legacy — method Card, no recorder, receivedAt =
     * paidAt, and the same derived key as 8.x. Since 8.4 (AID-1778) it also
     * accepts PARTIALLY_PAID invoices and allocates exactly each invoice's
     * amountOutstanding(), so $amount must equal the under-lock sum of the
     * outstandings; wholly unpaid invoices keep the 8.3 behavior because
     * outstanding === total when no active allocations exist.
     *
     * It always reads (key pre-check, eligibility) before process(), so a
     * caller transaction around register() is never in process()'s tolerated
     * "wrapper without prior read" class; its status precondition (SENT,
     * OVERDUE, PENDING and PARTIALLY_PAID since 8.4, re-checked by process()
     * under Legacy) makes that fail-safe. A stale snapshot between the two
     * transactions can still only refuse (process() validates before any
     * write): since 8.4 a PARTIAL racing receive() surfaces as
     * AllocationExceedsInvoiceException (the invoice stays PARTIALLY_PAID and
     * the fresh outstanding is smaller than the allocation built earlier),
     * while a fully-settling one keeps surfacing as notPayableStatus. A
     * same-key replay is unaffected by outstanding drift: it compares
     * immutable payload fields only and returns the original payment
     * verbatim.
     *
     * @param  list<string>  $invoiceIds
     */
    public function register(
        string $billableUserId,
        array $invoiceIds,
        DateTimeInterface $paidAt,
        FixedDecimal $amount,
        string $currency,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): GroupedPayment {
        $key      = $idempotencyKey ?? $this->deriveIdempotencyKey($billableUserId, $invoiceIds, $currency, $amount);
        $payments = app(PaymentService::class);

        $outcome = DB::transaction(function () use ($payments, $billableUserId, $invoiceIds, $paidAt, $amount, $currency, $reference, $key): GroupedPayment|ReceiveRequest {
            $existing = GroupedPayment::where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->isReversed()) {
                    throw IdempotencyConflictException::keySpentByReversal($key); // D2: spent key
                }
                $replay = ReceiveRequest::forLegacyReplay($billableUserId, $invoiceIds, $amount, $currency, $paidAt, $reference, $key);
                if ($existing->isPosted() && $payments->replayMatches($existing, $replay, ReplayPolicy::Legacy)) {
                    return $existing->load('invoices');
                }

                throw IdempotencyConflictException::forKey($key);
            }

            $orderedIds = $invoiceIds;
            sort($orderedIds); // deterministic lock order (deadlock-safe)

            $invoices = Invoice::whereIn('id', $orderedIds)
                ->with('companyFiscalConfig')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertEligible($billableUserId, $currency, $orderedIds, $invoices, $amount);

            return $this->legacyRequest($billableUserId, $invoices, $paidAt, $amount, $currency, $reference, $key);
        });

        if ($outcome instanceof GroupedPayment) {
            return $outcome;
        }

        // process() re-locks and re-validates on its own instances: it is the authoritative check.
        return $payments->process($outcome, ReplayPolicy::Legacy);
    }

    /**
     * Void a card-path payment (AID-961 contract, unchanged signature and
     * return): the payment and its allocations are reversed, every invoice is
     * recomputed from the allocations that remain, and a second call is a
     * stable no-op. Since 8.3 (AID-962) it delegates to PaymentService::reverse()
     * and no longer reads the deprecated `previous_*` snapshot.
     *
     * Must NOT run inside any caller transaction, with or without a prior
     * read: PaymentService::reverse() reads the payment's invoice ids before
     * opening its transaction, and inside a wrapper that read pins the
     * snapshot before the invoice locks, so the recomputation reads stale
     * allocations (spec §13 rows 40-41). Call it outside your transaction.
     */
    public function reverse(GroupedPayment $payment, string $reason, ?string $reversedBy = null): GroupedPayment
    {
        return app(PaymentService::class)->reverse($payment, $reason, $reversedBy);
    }

    /**
     * @param  list<string>  $invoiceIds
     * @param  Collection<int, Invoice>  $invoices
     */
    private function assertEligible(string $billableUserId, string $currency, array $invoiceIds, Collection $invoices, FixedDecimal $amount): void
    {
        if ($invoiceIds === []) {
            throw GroupedPaymentValidationException::emptyInvoiceList();
        }

        if (count($invoiceIds) !== count(array_unique($invoiceIds))) {
            $dupes = array_keys(array_filter(array_count_values($invoiceIds), fn (int $n): bool => $n > 1));
            throw GroupedPaymentValidationException::duplicateInvoices(array_map(static fn ($id): string => (string) $id, $dupes));
        }

        $found   = $invoices->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $missing = array_values(array_diff($invoiceIds, $found));
        if ($missing !== []) {
            throw GroupedPaymentValidationException::invoicesNotFound($missing);
        }

        $payableStatuses = Ledger::legacyPayableStatuses();
        $sum             = FixedDecimal::zero(2);

        // One query for the whole batch instead of an exists() per invoice
        // inside the loop (AID-391).
        $activelyPaidIds = DB::table('grouped_payment_invoice')
            ->whereIn('active_invoice_id', $found)
            ->pluck('active_invoice_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($invoices as $invoice) {
            if ((string) $invoice->billable_user_id !== $billableUserId) {
                throw GroupedPaymentValidationException::mixedUsers();
            }

            // D3: validate currency against the invoice's effective fiscal currency.
            $invoiceCurrency = $invoice->companyFiscalConfig?->currency;
            if ($invoiceCurrency !== null && $invoiceCurrency !== $currency) {
                throw GroupedPaymentValidationException::currencyMismatch((string) $invoice->id, $currency, $invoiceCurrency);
            }

            if ($invoice->serie === InvoiceSerieType::PROFORMA) {
                throw GroupedPaymentValidationException::proformaNotPayable((string) $invoice->id);
            }

            if (! in_array($invoice->status, $payableStatuses, true)) {
                throw GroupedPaymentValidationException::notPayableStatus((string) $invoice->id, $invoice->status->value);
            }

            if ($invoice->status !== InvoiceStatus::PARTIALLY_PAID
                && in_array((string) $invoice->id, $activelyPaidIds, true)) {
                throw GroupedPaymentValidationException::alreadyActivelyPaid((string) $invoice->id);
            }

            // AID-1778: the amount must equal the sum of the outstandings read
            // under the lock; a PARTIALLY_PAID invoice contributes exactly what
            // is left, and an active pivot no longer refuses it (the widened
            // machinery keeps the one-charge-per-invoice intent: a card payment
            // that settles the outstanding moves the invoice to PAID, and a
            // second register() is then refused by the status list). A
            // fully-covered invoice is either PAID (status-refused) or
            // stale-stamped (guard-refused above), so a zero outstanding
            // cannot reach the allocations.
            $sum = $sum->plus($invoice->amountOutstanding());
        }

        if ($sum->compareTo($amount) !== 0) {
            throw GroupedPaymentValidationException::amountMismatch($sum->unscaledValue(), $amount->unscaledValue());
        }
    }

    /**
     * The receive request equivalent to an eligible register() call: one
     * allocation per invoice for its amountOutstanding() (AID-1778: a
     * PARTIALLY_PAID invoice contributes exactly what is left; a wholly
     * unpaid one contributes its full total, as 8.3 did), method Card, no
     * recorder. The notPayableAmount floor stays on total_amount.
     *
     * @param  Collection<int, Invoice>  $invoices  locked and eligible
     *
     * @throws GroupedPaymentValidationException when an invoice total is not positive
     */
    private function legacyRequest(string $billableUserId, Collection $invoices, DateTimeInterface $paidAt, FixedDecimal $amount, string $currency, ?string $reference, string $key): ReceiveRequest
    {
        $allocations = [];
        foreach ($invoices as $invoice) {
            if (! $invoice->total_amount->isPositive()) {
                throw GroupedPaymentValidationException::notPayableAmount((string) $invoice->id, $invoice->total_amount->unscaledValue());
            }
            $allocations[] = new Allocation((string) $invoice->id, $invoice->amountOutstanding());
        }

        return ReceiveRequest::fromReceive(
            $billableUserId, $amount, $currency, $paidAt, PaymentMethod::Card, $reference, null,
            $allocations, CreditMovementKind::SURPLUS, $key,
        );
    }

    /** @param list<string> $invoiceIds */
    private function deriveIdempotencyKey(string $billableUserId, array $invoiceIds, string $currency, FixedDecimal $amount): string
    {
        $ids = $invoiceIds;
        sort($ids);

        return hash('sha256', implode('|', [$billableUserId, implode(',', $ids), $currency, (string) $amount->unscaledValue()]));
    }
}
