<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Payments;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\AllocationExceedsInvoiceException;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * The invoice-side steps shared by PaymentService and CustomerCredit (AID-962,
 * spec §5): the invoice lock, the §5.1.3 validation list and the allocation
 * row. Stateless; every method runs inside the caller's transaction, after
 * the caller's own locks.
 *
 * @internal Not part of the supported surface; called only by the two payment services.
 */
final class Ledger
{
    /**
     * Lock the invoices by id ascending (the global order) and key them by id.
     * One plain locking SELECT, nothing eager-loaded: it must come before
     * any plain read of the caller's transaction (spec §13.10 row 40).
     *
     * @param  list<string>  $invoiceIds
     * @return Collection<string, Invoice>
     */
    public static function lockInvoices(array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            /** @var Collection<string, Invoice> $none */
            $none = new Collection;

            return $none;
        }

        /** @var Collection<string, Invoice> $invoices */
        $invoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Invoice $invoice): string => (string) $invoice->id);

        return $invoices;
    }

    /**
     * @param  list<string>  $invoiceIds
     * @param  Collection<string, Invoice>  $invoices
     *
     * @throws GroupedPaymentValidationException when an id does not exist
     */
    public static function assertAllFound(array $invoiceIds, Collection $invoices): void
    {
        $missing = array_values(array_diff($invoiceIds, $invoices->keys()->map(fn ($id): string => (string) $id)->all()));
        if ($missing !== []) {
            throw GroupedPaymentValidationException::invoicesNotFound($missing);
        }
    }

    /**
     * The §5.1.3 validation list on ONE locked invoice, in the spec's order,
     * up to (not including) the outstanding check — see assertWithinOutstanding().
     *
     * $payableStatuses is explicit on purpose: receive(), allocate() and
     * CustomerCredit::apply() pass payableStatuses() (PARTIALLY_PAID is
     * payable), while the legacy card path (register(), via
     * PaymentService::process() under ReplayPolicy::Legacy) passes
     * legacyPayableStatuses() — so a partial receive() that lands between
     * register()'s eligibility check and process() surfaces as
     * notPayableStatus, the 8.2 contract, never as an outstanding overflow.
     *
     * @param  list<InvoiceStatus>  $payableStatuses
     *
     * @throws GroupedPaymentValidationException
     */
    public static function assertPayable(Invoice $invoice, string $billableUserId, string $currency, array $payableStatuses): void
    {
        $invoiceId = (string) $invoice->id;

        if ((string) $invoice->billable_user_id !== $billableUserId) {
            throw GroupedPaymentValidationException::mixedUsers();
        }

        // D3 parity with register(): a missing fiscal config (or currency) is not a mismatch.
        $invoiceCurrency = $invoice->companyFiscalConfig?->currency;
        if ($invoiceCurrency !== null && $invoiceCurrency !== $currency) {
            throw GroupedPaymentValidationException::currencyMismatch($invoiceId, $currency, $invoiceCurrency);
        }

        if ($invoice->serie === InvoiceSerieType::PROFORMA) {
            throw GroupedPaymentValidationException::proformaNotPayable($invoiceId);
        }

        if (! $invoice->isIssued()) {
            throw GroupedPaymentValidationException::notIssued($invoiceId);
        }

        if (! in_array($invoice->status, $payableStatuses, true)) {
            throw GroupedPaymentValidationException::notPayableStatus($invoiceId, $invoice->status->value);
        }

        if (! $invoice->total_amount->isPositive()) {
            throw GroupedPaymentValidationException::notPayableAmount($invoiceId, $invoice->total_amount->unscaledValue());
        }
    }

    /**
     * The last §5.1.3 check: $amount ≤ amountOutstanding(), read with a fresh
     * query on the locked invoice.
     *
     * @throws AllocationExceedsInvoiceException
     */
    public static function assertWithinOutstanding(Invoice $invoice, FixedDecimal $amount): void
    {
        $outstanding = $invoice->amountOutstanding();
        if ($amount->isGreaterThan($outstanding)) {
            throw AllocationExceedsInvoiceException::forInvoice((string) $invoice->id, $outstanding->toScale(2)->unscaledValue(), $amount->toScale(2)->unscaledValue());
        }
    }

    /**
     * The collection states an allocation may land on (spec §5.1.3).
     *
     * @return list<InvoiceStatus>
     */
    public static function payableStatuses(): array
    {
        return [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PENDING, InvoiceStatus::PARTIALLY_PAID];
    }

    /**
     * The collection states the 8.x card path accepts (AID-961):
     * GroupedPaymentService::register() settles whole invoices only, so a
     * PARTIALLY_PAID invoice is not payable there.
     *
     * @return list<InvoiceStatus>
     */
    public static function legacyPayableStatuses(): array
    {
        return [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PENDING];
    }

    /**
     * The status list process() applies under $policy: Legacy is register()'s.
     *
     * @return list<InvoiceStatus>
     */
    public static function payableStatusesFor(ReplayPolicy $policy): array
    {
        return $policy === ReplayPolicy::Legacy ? self::legacyPayableStatuses() : self::payableStatuses();
    }

    /**
     * One ACTIVE allocation row; previous_* snapshot the LOCKED instance
     * before its recomputation (written for 8.x readers, no longer read).
     */
    public static function createAllocation(string $paymentId, Invoice $invoice, FixedDecimal $amount, DateTimeInterface $appliedAt): PaymentAllocation
    {
        return PaymentAllocation::query()->create([
            'grouped_payment_id' => $paymentId,
            'invoice_id'         => $invoice->id,
            'applied_amount'     => $amount,
            'status'             => AllocationStatus::ACTIVE,
            'applied_at'         => $appliedAt,
            'active_invoice_id'  => $invoice->id,
            'previous_status'    => $invoice->status->value,
            'previous_paid_at'   => $invoice->paid_at,
        ]);
    }
}
