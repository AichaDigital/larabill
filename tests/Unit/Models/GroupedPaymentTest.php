<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
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
        'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    $payment->invoices()->attach($invoice->id, [
        'applied_amount' => 5000, 'previous_status' => $invoice->status->value, 'active_invoice_id' => $invoice->id,
    ]);
    expect($payment->invoices)->toHaveCount(1)
        ->and($invoice->fresh()->groupedPayments)->toHaveCount(1);
});

it('computes the unallocated amount over active allocations and the available credit over active entries (AID-962)', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2, 'amount' => cents(10000)]);

    foreach ([[6000, 0], [3000, 1]] as [$applied, $status]) {
        DB::table('grouped_payment_invoice')->insert([
            'grouped_payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'applied_amount' => $applied,
            'previous_status'    => $invoice->status->value, 'active_invoice_id' => $status === 0 ? $invoice->id : null,
            'status'             => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $entry = CreditMovement::factory()->create([
        'grouped_payment_id' => $payment->id, 'billable_user_id' => TestCase::USER_UUID_2, 'amount' => cents(4000),
    ]);
    CreditMovement::factory()->create([
        'grouped_payment_id'   => $payment->id, 'billable_user_id' => TestCase::USER_UUID_2,
        'kind'                 => CreditMovementKind::APPLIED, 'amount' => cents(-1000),
        'consumes_movement_id' => $entry->id,
    ]);

    expect($payment->unallocatedAmount()->unscaledValue())->toBe(4000)
        ->and($payment->availableCredit()->unscaledValue())->toBe(3000)
        ->and($payment->allocations)->toHaveCount(2)
        ->and($payment->creditMovements)->toHaveCount(2)
        ->and($payment->method)->toBe(PaymentMethod::Card)
        ->and($payment->recorded_by)->toBeNull();
});
