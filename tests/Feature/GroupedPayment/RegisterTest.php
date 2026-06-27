<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\GroupedPaymentValidationException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GroupedPaymentService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\DB;

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

it('rejects an empty invoice list', function () {
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [], now(), cents(0), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects duplicate invoice ids', function () {
    $a = makeSentInvoice(5000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id, $a->id], now(), cents(10000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a nonexistent invoice id', function () {
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [(string) \Illuminate\Support\Str::orderedUuid()], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects invoices belonging to different billable users', function () {
    $a = makeSentInvoice(5000);
    $b = Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_3,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id, $b->id], now(), cents(10000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a currency that differs from the invoice fiscal config (D3)', function () {
    $config = CompanyFiscalConfig::factory()->create(['currency' => 'USD']);
    $usd = Invoice::factory()->sent()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false, 'paid_at' => null,
        'company_fiscal_config_id' => $config->id,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$usd->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a proforma', function () {
    $p = Invoice::factory()->proforma()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$p->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects a draft (not-payable status)', function () {
    $d = Invoice::factory()->draft()->create([
        'user_id' => TestCase::USER_UUID_1, 'billable_user_id' => TestCase::USER_UUID_2,
        'total_amount' => cents(5000), 'is_immutable' => false,
    ]);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$d->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects an amount that does not equal the sum of totals', function () {
    $a = makeSentInvoice(6000);
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5999), 'EUR');
})->throws(GroupedPaymentValidationException::class);

it('rejects an invoice already covered by an active payment', function () {
    $payment = GroupedPayment::factory()->create(['billable_user_id' => TestCase::USER_UUID_2]);
    $a = makeSentInvoice(5000); // stays SENT — not paid, payable status

    DB::table('grouped_payment_invoice')->insert([
        'grouped_payment_id' => $payment->id,
        'invoice_id'         => $a->id,
        'applied_amount'     => 5000,
        'previous_status'    => InvoiceStatus::SENT->value,
        'previous_paid_at'   => null,
        'active_invoice_id'  => $a->id, // pivot marks this invoice as actively covered
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Invoice is still SENT (payable), so notPayableStatus is NOT thrown;
    // the alreadyActivelyPaid branch is reached and throws.
    app(GroupedPaymentService::class)->register(TestCase::USER_UUID_2, [$a->id], now(), cents(5000), 'EUR');
})->throws(GroupedPaymentValidationException::class, 'already covered by an active grouped payment');
