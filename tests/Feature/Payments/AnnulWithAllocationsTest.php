<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Exceptions\InvalidInvoiceStatusException;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\Support\PaymentFixtures;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rejects PARTIALLY_PAID as a birth status', function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $customer = TestUser::factory()->create();

    expect(fn () => app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $customer->id,
        'status'           => 'partially_paid',
        'items'            => [],
    ]))->toThrow(InvalidInvoiceStatusException::class);
});

it('refuses to annul an invoice with an active allocation and accepts it once reversed', function () {
    $invoice = PaymentFixtures::sentInvoice(12100);
    app(PaymentService::class)->receive(
        (string) $invoice->billable_user_id, cents(5000), 'EUR', now()->subDay(), PaymentMethod::BankTransfer, 'TRF-1', null,
        [new Allocation((string) $invoice->id, cents(5000))],
    );
    $invoice = $invoice->fresh();

    expect(fn () => app(SettlementService::class)->annul($invoice))
        ->toThrow(InvoiceNotAnnullableException::class);

    // The refusal changes nothing: neither the row nor the caller's instance.
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PARTIALLY_PAID)
        ->and($invoice->status)->toBe(InvoiceStatus::PARTIALLY_PAID);

    DB::table('grouped_payment_invoice')->update(['status' => 1, 'active_invoice_id' => null]);

    expect(app(SettlementService::class)->annul($invoice->fresh())->status)->toBe(InvoiceStatus::CANCELLED);
});
