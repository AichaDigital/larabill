<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/*
 * AID-962 §4.5 — Invoice collection sums over raw allocation rows:
 * only ACTIVE rows (status = 0) count.
 */

function aid962RawAllocation(GroupedPayment $payment, Invoice $invoice, int $cents, AllocationStatus $status): void
{
    DB::table('grouped_payment_invoice')->insert([
        'grouped_payment_id' => $payment->id,
        'invoice_id'         => $invoice->id,
        'applied_amount'     => $cents,
        'previous_status'    => InvoiceStatus::SENT->value,
        'previous_paid_at'   => null,
        'active_invoice_id'  => $status === AllocationStatus::ACTIVE ? $invoice->id : null,
        'status'             => $status->value,
        'created_at'         => now(), 'updated_at' => now(),
    ]);
}

it('sums only the active allocations of an invoice', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2, 'amount' => cents(20000)]);

    aid962RawAllocation($payment, $invoice, 5000, AllocationStatus::ACTIVE);
    aid962RawAllocation($payment, $invoice, 2100, AllocationStatus::ACTIVE);
    aid962RawAllocation($payment, $invoice, 4000, AllocationStatus::REVERSED);

    expect($invoice->amountPaid()->unscaledValue())->toBe(7100)
        ->and($invoice->amountOutstanding()->unscaledValue())->toBe(5000)
        ->and($invoice->isPartiallyPaid())->toBeTrue()
        ->and($invoice->allocations)->toHaveCount(3)
        ->and($invoice->allocations()->active()->count())->toBe(2);
});

it('is not partially paid with nothing or everything paid', function () {
    $unpaid  = PaymentFixtures::sentInvoice(12100);
    $paid    = PaymentFixtures::sentInvoice(12100);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    aid962RawAllocation($payment, $paid, 12100, AllocationStatus::ACTIVE);

    expect($unpaid->amountPaid()->isZero())->toBeTrue()
        ->and($unpaid->amountOutstanding()->unscaledValue())->toBe(12100)
        ->and($unpaid->isPartiallyPaid())->toBeFalse()
        ->and($paid->amountOutstanding()->isZero())->toBeTrue()
        ->and($paid->isPartiallyPaid())->toBeFalse();
});

it('models the allocation row as a pivot with casts and relations', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    aid962RawAllocation($payment, $invoice, 5000, AllocationStatus::ACTIVE);
    aid962RawAllocation($payment, $invoice, 4000, AllocationStatus::REVERSED);

    $active = PaymentAllocation::query()->active()->sole();

    expect($active->id)->toBeInt()
        ->and($active->applied_amount->unscaledValue())->toBe(5000)
        ->and($active->status)->toBe(AllocationStatus::ACTIVE)
        ->and($active->isActive())->toBeTrue()
        ->and($active->payment->id)->toBe($payment->id)
        ->and($active->invoice->id)->toBe($invoice->id)
        ->and($active->creditMovements)->toHaveCount(0)
        ->and(PaymentAllocation::query()->where('status', AllocationStatus::REVERSED->value)->sole()->isActive())->toBeFalse();
});
