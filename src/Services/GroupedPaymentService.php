<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
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

        try {
            return DB::transaction(function () use ($billableUserId, $invoiceIds, $paidAt, $amount, $currency, $reference, $key): GroupedPayment {
                $existing = GroupedPayment::where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    if ($existing->isReversed()) {
                        throw IdempotencyConflictException::keySpentByReversal($key); // D2: spent key
                    }
                    if ($existing->isPosted() && $this->payloadMatches($existing, $billableUserId, $invoiceIds, $amount, $currency)) {
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
        } catch (QueryException $e) {
            // Concurrency backstop: another tx won the unique idempotency_key race (the tx rolled back).
            if ($this->isIdempotencyKeyViolation($e)) {
                $raced = GroupedPayment::where('idempotency_key', $key)->first();
                if ($raced !== null && $raced->isPosted() && $this->payloadMatches($raced, $billableUserId, $invoiceIds, $amount, $currency)) {
                    return $raced->load('invoices');
                }
                throw IdempotencyConflictException::forKey($key);
            }
            throw $e;
        }
    }

    public function reverse(GroupedPayment $payment, string $reason, ?string $reversedBy = null): GroupedPayment
    {
        return DB::transaction(function () use ($payment, $reason, $reversedBy): GroupedPayment {
            // Re-read + lock so two concurrent reversals can't both write (Codex #8).
            $locked = GroupedPayment::whereKey($payment->getKey())->lockForUpdate()->first() ?? $payment;

            if ($locked->isReversed()) {
                return $locked->load('invoices'); // stable no-op, audit fields untouched
            }

            $locked->update([
                'status'         => GroupedPaymentStatus::REVERSED,
                'reversed_at'    => now(),
                'reversed_by'    => $reversedBy,
                'reverse_reason' => $reason,
            ]);

            $pivotRows = DB::table('grouped_payment_invoice')
                ->where('grouped_payment_id', $locked->id)
                ->get();

            foreach ($pivotRows as $pivotRow) {
                $invoice = Invoice::find((string) $pivotRow->invoice_id);
                if ($invoice === null) {
                    continue;
                }

                $invoice->restoreStateViaGroupedPaymentReversal(
                    InvoiceStatus::from((int) $pivotRow->previous_status),
                    $pivotRow->previous_paid_at !== null ? Carbon::parse($pivotRow->previous_paid_at) : null,
                );
            }

            DB::table('grouped_payment_invoice')
                ->where('grouped_payment_id', $locked->id)
                ->update(['active_invoice_id' => null]);

            return $locked->load('invoices');
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
            throw GroupedPaymentValidationException::duplicateInvoices(array_map(static fn ($id): string => (string) $id, $dupes));
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
    private function payloadMatches(GroupedPayment $existing, string $billableUserId, array $invoiceIds, FixedDecimal $amount, string $currency): bool
    {
        if ((string) $existing->billable_user_id !== $billableUserId || $existing->currency !== $currency) {
            return false;
        }
        if ($existing->amount->compareTo($amount) !== 0) {
            return false;
        }
        $existingIds  = $existing->invoices->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $requestedIds = collect($invoiceIds)->map(fn ($id): string => (string) $id)->sort()->values()->all();

        return $existingIds === $requestedIds;
    }

    private function isIdempotencyKeyViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation; message names the column/index.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'idempotency_key');
    }

    /** @param list<string> $invoiceIds */
    private function deriveIdempotencyKey(string $billableUserId, array $invoiceIds, string $currency, FixedDecimal $amount): string
    {
        $ids = $invoiceIds;
        sort($ids);

        return hash('sha256', implode('|', [$billableUserId, implode(',', $ids), $currency, (string) $amount->unscaledValue()]));
    }
}
