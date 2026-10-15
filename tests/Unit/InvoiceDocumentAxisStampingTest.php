<?php

declare(strict_types=1);

use AichaDigital\Larabill\DTO\ProformaConversionResult;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\TestCase;

/*
 * ADR-016 §8.5 (PR-2 of AID-1627) — the transitional stamping FLIPPED.
 *
 * PR-1 stamped doc=ISSUED on every new numbered fiscal row because the v6
 * engine numbered at birth. PR-2 rewires `createInvoice()` to the two-phase
 * contract: fiscal births are UNNUMBERED DRAFTs (five number/issuance fields
 * NULL, submission axis NULL) and the correlative moves into `issue()` — the
 * transitional lie is dead. The PROFORMA path keeps its birth numbering on
 * its own serie with NULL on both axes (§3.1) — this file re-points the PR-1
 * stamping intent to the flipped reality, it does not delete it.
 */

beforeEach(function (): void {
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

it('births every explicit-draft fiscal row an UNNUMBERED DRAFT: five fields NULL, submission NULL, collection DRAFT (ADR-016 §7.2 — the bare call is result-compatible and reaches issuance)', function (): void {
    $invoice = app(InvoiceService::class)->createInvoice([
        'status'           => 'draft',
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [
            ['description' => 'Hosting', 'quantity' => 100, 'base_price' => 1000],
        ],
    ]);

    // §7.1 phase 1: the durable unnumbered DRAFT — no numbering call happened.
    expect($invoice->fiscal_number)->toBeNull()
        ->and($invoice->series_number)->toBeNull()
        ->and($invoice->fiscal_year)->toBeNull()
        ->and($invoice->invoice_date)->toBeNull()
        ->and($invoice->issued_at)->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::DRAFT)
        // The document axis exists and reads DRAFT; the submission axis waits
        // for issuance (§8.2 C4/C6: five-NULL ⇔ not-ISSUED, submission NULL).
        ->and($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($invoice->fiscal_submission_status)->toBeNull();
});

it('births every FISCAL type (INVOICE, SIMPLIFIED, RECTIFICATIVE) unnumbered, the PROFORMA type numbered on its own serie', function (): void {
    $service = app(InvoiceService::class);

    foreach (InvoiceSerieType::fiscalTypes() as $type) {
        $invoice = $service->createInvoice([
            'status'           => 'draft', // §7.2: the unnumbered-birth shape is the explicit draft
            'billable_user_id' => TestCase::USER_UUID_1,
            'type'             => $type->label(),
            'items'            => [
                ['description' => 'Item', 'quantity' => 100, 'base_price' => 500],
            ],
        ]);

        // §7.1: no fiscal birth consumes a correlative, whatever the type.
        expect($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
            ->and($invoice->fiscal_number)->toBeNull()
            ->and($invoice->fiscal_submission_status)->toBeNull();
    }

    // §3.1: the proforma path is the deliberate exception, verbatim v6.
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [
            ['description' => 'Proforma item', 'quantity' => 100, 'base_price' => 2000],
        ],
    ]);

    expect($proforma->serie)->toBe(InvoiceSerieType::PROFORMA)
        ->and($proforma->fiscal_number)->not->toBeNull()
        ->and($proforma->series_number)->not->toBeNull()
        ->and($proforma->fiscal_year)->not->toBeNull()
        ->and($proforma->invoice_date)->not->toBeNull()
        ->and($proforma->issued_at)->not->toBeNull()
        ->and($proforma->invoice_document_status)->toBeNull()
        ->and($proforma->fiscal_submission_status)->toBeNull();
});

it('keeps the conversion flow coherent: the converted document is PREPARED, the proforma is CONVERTING with NULL axes', function (): void {
    $service = app(InvoiceService::class);

    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [
            ['description' => 'To convert', 'quantity' => 100, 'base_price' => 3000],
        ],
    ]);

    $result = $service->convertProformaToInvoice($proforma);

    // §9: PREPARED — unnumbered, submission NULL; the collection axis stays
    // at DRAFT (the old `pending` encoded the collapsed lifecycle that dies).
    expect($result)->toBeInstanceOf(ProformaConversionResult::class)
        ->and($result->invoice->serie)->not->toBe(InvoiceSerieType::PROFORMA)
        ->and($result->invoice->fiscal_number)->toBeNull()
        ->and($result->invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($result->invoice->fiscal_submission_status)->toBeNull()
        ->and($result->invoice->status)->toBe(InvoiceStatus::DRAFT);

    // §5.2: the proforma's collection state is CONVERTING at conversion (not
    // CONVERTED — that stamp moved to issue()); its document axis is still
    // NULL (proformas are invisible to the axis, §8.2).
    $proforma->refresh();
    expect($proforma->status)->toBe(InvoiceStatus::CONVERTING)
        ->and($proforma->invoice_document_status)->toBeNull()
        ->and($proforma->fiscal_submission_status)->toBeNull();

    // §6 step 8: issuance stamps the proforma CONVERTED.
    $issued = $service->issue($result->invoice);
    expect($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->fiscal_submission_status)->toBe(FiscalSubmissionStatus::NOT_REQUIRED)
        ->and($issued->status)->toBe(InvoiceStatus::DRAFT);

    $proforma->refresh();
    expect($proforma->status)->toBe(InvoiceStatus::CONVERTED);
});

it('keeps the collection and immutability flows working over an ISSUED row', function (): void {
    $service = app(InvoiceService::class);

    $invoice = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [
            ['description' => 'Item', 'quantity' => 100, 'base_price' => 1000],
        ],
    ]);

    $issued = $service->issue($invoice);

    // Collection state remains writable on an ISSUED document row (the
    // named grouped-payment path, preserved explicitly by §6.5).
    $issued->markAsPaidViaGroupedPayment(now());
    expect($issued->refresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED);

    // Immutability seal works over the issued row.
    $issued->makeImmutable();
    expect($issued->is_immutable)->toBeTrue();
});
