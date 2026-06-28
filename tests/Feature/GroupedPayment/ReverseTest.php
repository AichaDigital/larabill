<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Tests\TestCase;

function sentInvoice(int $cents): Invoice
{
    return Invoice::factory()->sent()->create([
        'user_id'      => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents($cents), 'is_immutable' => false, 'paid_at' => null,
    ]);
}

it('reverses a payment and restores each invoice to its prior state', function () {
    $a       = sentInvoice(5000);
    $svc     = app(GroupedPaymentService::class);
    $payment = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'r-1');
    expect($a->fresh()->status)->toBe(InvoiceStatus::PAID);

    $reversed = $svc->reverse($payment, 'customer refund', TestCase::USER_UUID_3);

    expect($reversed->status)->toBe(GroupedPaymentStatus::REVERSED)
        ->and($reversed->reverse_reason)->toBe('customer refund')
        ->and($reversed->reversed_by)->toBe(TestCase::USER_UUID_3);
    $a = $a->fresh();
    expect($a->status)->toBe(InvoiceStatus::SENT)->and($a->paid_at)->toBeNull();
    expect($reversed->invoices()->where('invoice_id', $a->id)->first()->pivot->active_invoice_id)->toBeNull();
});

it('is a stable no-op on a second reverse (audit fields untouched)', function () {
    $a       = sentInvoice(5000);
    $svc     = app(GroupedPaymentService::class);
    $payment = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'r-2');
    $first   = $svc->reverse($payment, 'first reason', TestCase::USER_UUID_3);
    $again   = $svc->reverse($first->fresh(), 'second reason', TestCase::USER_UUID_1);
    expect($again->reverse_reason)->toBe('first reason')->and($again->reversed_by)->toBe(TestCase::USER_UUID_3);
});

it('lets a reversed invoice join a NEW payment with a fresh key (D2)', function () {
    $a   = sentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $p1  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 're-1');
    $svc->reverse($p1, 'undo', TestCase::USER_UUID_3);

    $p2 = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 're-2');
    expect($p2->status)->toBe(GroupedPaymentStatus::POSTED)->and($a->fresh()->status)->toBe(InvoiceStatus::PAID);
});

it('refuses re-pay after reverse when the spent/derived key is reused (D2)', function () {
    $a   = sentInvoice(5000);
    $svc = app(GroupedPaymentService::class);
    $p1  = $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'spent');
    $svc->reverse($p1, 'undo', TestCase::USER_UUID_3);
    $svc->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR', idempotencyKey: 'spent');
})->throws(IdempotencyConflictException::class);
