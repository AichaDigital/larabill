<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create an invoice', function () {
    $invoice = new Invoice([
        'fiscal_number'     => 'FAC-2025-000001',
        'prefix'            => 'FAC',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'series_number'     => TestCase::USER_UUID_1,
        'fiscal_year'       => 2025,
        'invoice_date'      => now()->toDateString(),
        'issued_at'         => now(),
        'status'            => InvoiceStatus::DRAFT->value,
        'user_id'           => TestCase::USER_UUID_1,
        'taxable_amount'    => cents(10000), // €100.00
        'total_tax_amount'  => cents(2100),  // €21.00
        'total_amount'      => cents(12100), // €121.00
    ]);

    expect($invoice->fiscal_number)->toBe('FAC-2025-000001');
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    expect($invoice->status)->toBe(InvoiceStatus::DRAFT);
    expect($invoice->user_id)->toBe(TestCase::USER_UUID_1);
    expect($invoice->taxable_amount->unscaledValue())->toBe(10000);
    expect($invoice->total_tax_amount->unscaledValue())->toBe(2100);
    expect($invoice->total_amount->unscaledValue())->toBe(12100);
});

it('can make an invoice immutable', function () {
    $invoice = new Invoice([
        'fiscal_number'  => 'FAC-2025-000001',
        'prefix'         => 'FAC',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'series_number'  => TestCase::USER_UUID_1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        // ADR-016 §8.2: a hand-built fiscal row must declare its document
        // axis (the enforcer rejects the unstamped shape by design).
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'fiscal_submission_status' => FiscalSubmissionStatus::LEGACY_UNKNOWN->value,
        'status'                   => InvoiceStatus::DRAFT->value,
        'user_id'                  => TestCase::USER_UUID_1,
        'taxable_amount'           => cents(10000), // €100.00
        'tax_amount'               => 2100,         // €21.00 (alias, not a cast attribute)
        'total_amount'             => cents(12100), // €121.00
    ]);

    $invoice->makeImmutable();

    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->immutable_at)->not->toBeNull();
});

it('identifies draft invoices', function () {
    $draft = new Invoice([
        'status' => InvoiceStatus::DRAFT->value,
    ]);

    $sent = new Invoice([
        'status' => InvoiceStatus::SENT->value,
    ]);

    expect($draft->isDraft())->toBeTrue();
    expect($sent->isDraft())->toBeFalse();
});

// AID-1687: the document-axis predicates (ADR-016 §5.2). isDraft() reads the
// COLLECTION axis; these read the DOCUMENT axis. The two diverge by design:
// issue() leaves the collection status at DRAFT, so a numbered, immutable
// invoice is still isDraft() until the consumer stamps a collection status.
it('identifies issued and unissued documents on the document axis', function () {
    $issued = new Invoice([
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'status'                   => InvoiceStatus::DRAFT->value,
    ]);

    $unissuedDraft = new Invoice([
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::DRAFT->value,
        'status'                   => InvoiceStatus::DRAFT->value,
    ]);

    $prepared = new Invoice([
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::PREPARED->value,
        'status'                   => InvoiceStatus::DRAFT->value,
    ]);

    $cancelled = new Invoice([
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::CANCELLED->value,
        'status'                   => InvoiceStatus::CANCELLED->value,
    ]);

    expect($issued->isIssued())->toBeTrue();
    expect($issued->isUnissued())->toBeFalse();

    expect($unissuedDraft->isIssued())->toBeFalse();
    expect($unissuedDraft->isUnissued())->toBeTrue();

    expect($prepared->isIssued())->toBeFalse();
    expect($prepared->isUnissued())->toBeTrue();

    expect($cancelled->isIssued())->toBeFalse();
    expect($cancelled->isUnissued())->toBeTrue();
});

it('treats a proforma row (absent document axis) as unissued, never issued', function () {
    // ADR-016 §8.2 C2: the document axis does not exist on proforma rows
    // (invoice_document_status IS NULL). The predicates read the axis, so a
    // proforma is never issued and — by the plain negation contract — reads
    // as unissued. Callers that must exclude proformas gate on the serie.
    $proforma = new Invoice([
        'serie'                    => InvoiceSerieType::PROFORMA->value,
        'invoice_document_status'  => null,
        'status'                   => InvoiceStatus::DRAFT->value,
    ]);

    expect($proforma->isIssued())->toBeFalse();
    expect($proforma->isUnissued())->toBeTrue();
});

// AID-1687: the divergence pin. A numbered, fiscally immutable invoice whose
// collection status was never stamped past DRAFT is still isDraft() — the
// billing axis and the document axis are independent (ADR-016 §5.2).
it('keeps isDraft() true for an issued invoice whose collection status is still DRAFT', function () {
    $issuedUntouched = new Invoice([
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::ISSUED->value,
        'status'                   => InvoiceStatus::DRAFT->value,
    ]);

    expect($issuedUntouched->isIssued())->toBeTrue();
    expect($issuedUntouched->isDraft())->toBeTrue();
});

it('cannot change fiscal content of an immutable invoice', function () {
    $invoice = new Invoice([
        'fiscal_number'  => 'FAC-2025-000001',
        'prefix'         => 'FAC',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'series_number'  => TestCase::USER_UUID_1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => TestCase::USER_UUID_1,
        'is_immutable'   => true,
        'immutable_at'   => now(),
    ]);

    // ADR-016 §6.5: fiscal content stays closed on a protected row — through
    // update() AND through the attribute-assignment + save() bypass (D8).
    expect(fn () => $invoice->update(['fiscal_number' => 'FAC-2025-999999']))
        ->toThrow(Exception::class);
    expect(fn () => $invoice->update(['total_amount' => cents(9999)]))
        ->toThrow(Exception::class);
});

it('permits the deliberate collection-state transitions on an immutable invoice (ADR-016 §6.5)', function () {
    // A persisted protected fixture: the legacy numbered shape, sealed.
    $invoice = Invoice::factory()->create([
        'status'       => InvoiceStatus::DRAFT->value,
        'user_id'      => TestCase::USER_UUID_1,
        'is_immutable' => true,
        'immutable_at' => now(),
    ]);

    // Collection/lifecycle state is NOT fiscal content: the §6.5 guards
    // deliberately preserve the grouped-payment class of transitions.
    expect($invoice->update(['status' => InvoiceStatus::PAID->value]))->toBeTrue()
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::PAID);
});

it('closes the save() bypass on fiscal content of an immutable invoice (ADR-016 §6.5 D8)', function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $issued = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ], ['make_immutable' => true]);
    $issued = app(InvoiceService::class)->issue($issued);

    // The v6 update() override did not fire here — the attribute-assignment
    // + save() path is exactly what the new updating guard reads.
    $issued->fiscal_number = 'FAC-2025-000099';

    expect(fn () => $issued->save())->toThrow(Exception::class, 'fiscally protected');
});
