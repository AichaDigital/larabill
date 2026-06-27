<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

it('marks an IMMUTABLE invoice as paid without tripping the update() guard', function () {
    $invoice = Invoice::factory()->sent()->immutable()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'paid_at' => null,
    ]);
    expect($invoice->is_immutable)->toBeTrue();

    $invoice->markAsPaidViaGroupedPayment(now());

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->fresh()->paid_at)->not->toBeNull();
});

it('restores collection state on an immutable invoice', function () {
    $invoice = Invoice::factory()->sent()->immutable()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'paid_at' => null,
    ]);
    $invoice->markAsPaidViaGroupedPayment(now());
    $invoice->restoreStateViaGroupedPaymentReversal(InvoiceStatus::SENT, null);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::SENT)
        ->and($invoice->fresh()->paid_at)->toBeNull();
});

it('still blocks a plain update() on an immutable invoice (guard intact)', function () {
    $invoice = Invoice::factory()->immutable()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000),
    ]);
    expect(fn () => $invoice->update(['status' => InvoiceStatus::PAID->value, 'paid_at' => now()]))
        ->toThrow(Exception::class);
});
