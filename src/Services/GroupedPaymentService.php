<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use DateTimeInterface;
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

    /** @param list<string> $invoiceIds */
    private function deriveIdempotencyKey(string $billableUserId, array $invoiceIds, string $currency, FixedDecimal $amount): string
    {
        $ids = $invoiceIds;
        sort($ids);

        return hash('sha256', implode('|', [$billableUserId, implode(',', $ids), $currency, (string) $amount->unscaledValue()]));
    }
}
