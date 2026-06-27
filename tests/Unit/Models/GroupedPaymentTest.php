<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

it('creates grouped_payments with the expected columns', function () {
    expect(Schema::hasTable('grouped_payments'))->toBeTrue();
    expect(Schema::hasColumns('grouped_payments', [
        'id', 'billable_user_id', 'amount', 'currency', 'paid_at', 'reference',
        'idempotency_key', 'status', 'reversed_at', 'reversed_by', 'reverse_reason', 'notes',
    ]))->toBeTrue();
});

it('creates grouped_payment_invoice pivot with the expected columns', function () {
    expect(Schema::hasTable('grouped_payment_invoice'))->toBeTrue();
    expect(Schema::hasColumns('grouped_payment_invoice', [
        'id', 'grouped_payment_id', 'invoice_id', 'applied_amount',
        'previous_status', 'previous_paid_at', 'active_invoice_id',
    ]))->toBeTrue();
});

it('casts amount to FixedDecimal and status to the enum, with a valid default factory row', function () {
    $payment = GroupedPayment::factory()->create(['amount' => cents(10000)]); // no billable override → factory supplies a UUID
    expect($payment->amount)->toBeInstanceOf(FixedDecimal::class)
        ->and($payment->amount->unscaledValue())->toBe(10000)
        ->and($payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($payment->billable_user_id)->not->toBeNull();
});

it('relates a payment to its invoices and back', function () {
    $invoice = Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    $payment->invoices()->attach($invoice->id, [
        'applied_amount' => 5000, 'previous_status' => $invoice->status->value, 'active_invoice_id' => $invoice->id,
    ]);
    expect($payment->invoices)->toHaveCount(1)
        ->and($invoice->fresh()->groupedPayments)->toHaveCount(1);
});
