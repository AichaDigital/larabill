<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GroupedPaymentService
{
    /** @param list<string> $invoiceIds */
    public function register(
        string $billableUserId,
        array $invoiceIds,
        DateTimeInterface $paidAt,
        FixedDecimal $amount,
        string $currency,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): GroupedPayment {
        $key = $idempotencyKey ?? $this->deriveIdempotencyKey($billableUserId, $invoiceIds, $currency, $amount);

        return DB::transaction(function () use ($billableUserId, $invoiceIds, $paidAt, $amount, $currency, $reference, $key): GroupedPayment {
            $orderedIds = $invoiceIds;
            sort($orderedIds); // deterministic lock order (deadlock-safe)

            $invoices = Invoice::whereIn('id', $orderedIds)
                ->with('companyFiscalConfig')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertEligible($billableUserId, $currency, $orderedIds, $invoices, $amount);

            $payment = GroupedPayment::create([
                'billable_user_id' => $billableUserId,
                'amount'           => $amount,
                'currency'         => $currency,
                'paid_at'          => $paidAt,
                'reference'        => $reference,
                'idempotency_key'  => $key,
                'status'           => GroupedPaymentStatus::POSTED,
            ]);

            foreach ($invoices as $invoice) {
                $payment->invoices()->attach($invoice->id, [
                    'applied_amount'    => $invoice->total_amount->unscaledValue(),
                    'previous_status'   => $invoice->status->value,
                    'previous_paid_at'  => $invoice->paid_at,
                    'active_invoice_id' => $invoice->id,
                ]);

                $invoice->markAsPaidViaGroupedPayment($paidAt); // D1: works on immutable invoices
            }

            return $payment->load('invoices');
        });
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
            throw GroupedPaymentValidationException::duplicateInvoices(array_values($dupes));
        }

        $found   = $invoices->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $missing = array_values(array_diff($invoiceIds, $found));
        if ($missing !== []) {
            throw GroupedPaymentValidationException::invoicesNotFound($missing);
        }

        $payableStatuses = [InvoiceStatus::SENT, InvoiceStatus::OVERDUE, InvoiceStatus::PENDING];
        $sum             = FixedDecimal::zero(2);

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

            $activelyPaid = DB::table('grouped_payment_invoice')->where('active_invoice_id', $invoice->id)->exists();
            if ($activelyPaid) {
                throw GroupedPaymentValidationException::alreadyActivelyPaid((string) $invoice->id);
            }

            $sum = $sum->plus($invoice->total_amount);
        }

        if ($sum->compareTo($amount) !== 0) {
            throw GroupedPaymentValidationException::amountMismatch($sum->unscaledValue(), $amount->unscaledValue());
        }
    }

    /** @param list<string> $invoiceIds */
    private function deriveIdempotencyKey(string $billableUserId, array $invoiceIds, string $currency, FixedDecimal $amount): string
    {
        $ids = $invoiceIds;
        sort($ids);

        return hash('sha256', implode('|', [$billableUserId, implode(',', $ids), $currency, (string) $amount->unscaledValue()]));
    }
}
