<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\InvoiceNotIssuedException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    UserTaxProfile::factory()->create([
        'owner_user_id' => TestCase::USER_UUID_1,
        'tax_id'        => 'B12345678',
        'valid_until'   => null,
    ]);
});

// AID-1686 (from clientes AID-1670): since 8.0 a fiscal draft is durable and
// unnumbered — the five issuance fields are NULL — and every fiscal template
// reads `invoice_date->format()` unguarded. Rendering one died inside the
// Blade with `Call to a member function format() on null`. The package now
// fails closed BEFORE rendering with a typed rejection; the consumer decides
// what to show (option 1 of the ticket; a draft template is a later product
// decision, AID-1686).
it('rejects with a typed exception when generating the PDF of an unissued fiscal document', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);

    expect($draft->isIssued())->toBeFalse();

    (new DomPDFService)->generatePDF($draft);
})->throws(InvoiceNotIssuedException::class);

it('reports the unissued rejection through the PDFService frontier as a failure result', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);

    $result = (new PDFService)->generatePDF($draft);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('not been issued');
});

it('does not reject proformas with the unissued guard (their document axis is absent, ADR-016 §8.2 C2)', function () {
    // A proforma is not issued and never will be through issue(): its serie
    // excludes it from the guard's rejection. The guard boundary is exactly
    // "fiscal serie + unissued" — a proforma passes through untouched. (Its
    // render pipeline has a separate, pre-existing defect: proforma rows
    // carry no issuer snapshot by design, so the shipped template dies on
    // the company header. Ticketed apart — not this guard's concern.)
    $proforma = Invoice::factory()->proforma()->create([
        'user_id' => TestCase::USER_UUID_1,
    ]);

    expect(fn () => (new DomPDFService)->generatePDF($proforma))
        ->not->toThrow(InvoiceNotIssuedException::class);
});

it('keeps rendering issued fiscal documents', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);
    $issued = $service->issue($draft);

    expect($issued->isIssued())->toBeTrue();

    $result = (new PDFService)->generatePDF($issued);

    expect($result['success'])->toBeTrue();
});
