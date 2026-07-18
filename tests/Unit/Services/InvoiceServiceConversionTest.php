<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-554 (D1) — sequential contract of convertProformaToInvoice().
 *
 * The conversion path had no direct coverage; these pins freeze the
 * sequential behaviour the row-lock hardening must preserve. The concurrency
 * proof itself lives in tests/Concurrency/ProformaConversionConcurrencyTest.php
 * (fork-based, MySQL, RUN_CONCURRENCY_IT=1).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
});

it('converts a proforma into a final invoice and freezes the proforma', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $invoice = $service->convertProformaToInvoice($proforma);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->serie)->toBe(InvoiceSerieType::INVOICE)
        ->and($invoice->id)->not->toBe($proforma->id);

    $frozen = $proforma->fresh();
    expect($frozen->status)->toBe(InvoiceStatus::CONVERTED)
        ->and($frozen->is_immutable)->toBeTrue()
        ->and($frozen->converted_invoice_id)->toBe($invoice->id)
        ->and($frozen->converted_at)->not->toBeNull();
});

it('returns the already-created invoice when converting the same proforma again', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $first = $service->convertProformaToInvoice($proforma);

    // Deliberately pass the now-stale instance: the method must re-read the
    // committed state inside its transaction, not trust the caller's copy.
    $second = $service->convertProformaToInvoice($proforma);

    expect($second->id)->toBe($first->id)
        ->and(Invoice::where('serie', InvoiceSerieType::INVOICE->value)->count())->toBe(1);
});

it('rejects converting an invoice that is not a proforma', function () {
    $service = app(InvoiceService::class);
    $invoice = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);

    expect(fn () => $service->convertProformaToInvoice($invoice))
        ->toThrow(InvalidArgumentException::class, 'not a proforma');
});
