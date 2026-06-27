<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Tests\TestCase;

// Pinned non-immutable, unpaid SENT fixture (Codex #2: factory randomizes both).
function makeSentInvoice(int $totalCents): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents($totalCents), 'is_immutable' => false, 'paid_at' => null,
    ]);
}

it('settles a set of issued invoices in one posted payment', function () {
    $a = makeSentInvoice(6000);
    $b = makeSentInvoice(4000);

    $payment = app(GroupedPaymentService::class)->register(
        billableUserId: TestCase::USER_UUID_2, invoiceIds: [$a->id, $b->id],
        paidAt: now(), amount: cents(10000), currency: 'EUR', reference: 'TRF-001',
    );

    expect($payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($payment->amount->unscaledValue())->toBe(10000)
        ->and($payment->invoices)->toHaveCount(2);
    expect($a->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($a->fresh()->paid_at)->not->toBeNull()
        ->and($b->fresh()->status)->toBe(InvoiceStatus::PAID);

    $pivot = $payment->invoices()->where('invoice_id', $a->id)->first()->pivot;
    expect((int) $pivot->previous_status)->toBe(InvoiceStatus::SENT->value)
        ->and($pivot->active_invoice_id)->toBe($a->id);
});
