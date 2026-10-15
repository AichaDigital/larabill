<?php

declare(strict_types=1);

use AichaDigital\Larabill\DTO\ProformaConversionResult;
use AichaDigital\Larabill\Enums\FiscalSubmissionStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\FiscalConfigChangedException;
use AichaDigital\Larabill\Exceptions\InvoiceNotIssuableException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Support\RegionalContext;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * ADR-016 §6 (PR-2) — the `issue()` contract.
 *
 * Step order, idempotency BEFORE eligibility (round 3, S3), ONE issuance
 * instant governing the correlative AND the four stamped values (V4: the
 * fiscal year comes from RegionalContext, never the calendar year), the
 * config-derived submission stamp (§6 step 7), the collection axis staying
 * DRAFT (§5.2) and the conversion-born proforma moving CONVERTING →
 * CONVERTED (§6 step 8).
 */
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

it('issues a direct DRAFT: one correlative, five fields from ONE instant, doc ISSUED, collection DRAFT', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);

    $issued = $service->issue($draft);

    expect($issued->fiscal_number)->toBe('FAC-'.now()->year.'-000001')
        ->and($issued->series_number)->toBe(1)
        ->and($issued->fiscal_year)->toBe(RegionalContext::getFiscalYear(now()))
        ->and($issued->invoice_date)->toBeInstanceOf(Carbon::class)
        ->and($issued->issued_at)->not->toBeNull()
        // §6 step 5: the instant coherence — invoice_date is the instant's day.
        ->and($issued->invoice_date->toDateString())->toBe($issued->issued_at->toDateString())
        ->and($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->status)->toBe(InvoiceStatus::DRAFT) // §5.2: the axes stay separate
        ->and($issued->fiscal_submission_status)->toBe(FiscalSubmissionStatus::NOT_REQUIRED);

    // §6.1: the counter moved exactly once.
    expect(InvoiceSeriesControl::where('prefix', 'FAC')->sole()->last_number)->toBe(1);
});

it('is idempotent: an already-ISSUED invoice returns itself unchanged, BEFORE eligibility (§6 S3)', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);

    $first  = $service->issue($draft);
    $second = $service->issue($first);

    expect($second->id)->toBe($first->id)
        ->and($second->fiscal_number)->toBe($first->fiscal_number)
        ->and(InvoiceSeriesControl::where('prefix', 'FAC')->sole()->last_number)->toBe(1);
});

it('burns exactly ONE correlative across two sequential issuances', function () {
    $service = app(InvoiceService::class);

    $draftOne   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);
    $draftTwo   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);
    $issuedOne  = $service->issue($draftOne);
    $issuedTwo  = $service->issue($draftTwo);

    expect($issuedOne->series_number)->toBe(1)
        ->and($issuedTwo->series_number)->toBe(2)
        ->and(InvoiceSeriesControl::where('prefix', 'FAC')->sole()->last_number)->toBe(2);
});

it('refuses a CANCELLED document typed, naming the remedy', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);

    // Cancel through the consumer path (§10 last paragraph): legacy CANCELLED
    // + document CANCELLED + the audited instant with actor NULL.
    $draft->invoice_document_status = InvoiceDocumentStatus::CANCELLED;
    $draft->status                  = InvoiceStatus::CANCELLED;
    $draft->document_cancelled_at   = now();
    $draft->save();

    expect(fn () => $service->issue($draft))
        ->toThrow(InvoiceNotIssuableException::class, 'CANCELLED');
});

it('refuses a PROFORMA row typed: proformas have no issuance path', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [],
    ]);

    expect(fn () => $service->issue($proforma))
        ->toThrow(InvoiceNotIssuableException::class);
});

it('stamps the proforma CONVERTING → CONVERTED at issuance, never at conversion (§6 step 8)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    $result = $service->convertProformaToInvoice($proforma);

    expect($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTING);

    $service->issue($result->invoice);

    expect($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED)
        ->and($proforma->fresh()->is_immutable)->toBeTrue()
        ->and($proforma->fresh()->converted_invoice_id)->toBe($result->invoice->id);
});

it('keeps the fiscal year CONFIGURED, not calendar: a June date before a July-1 fiscal start (§6 V4)', function () {
    // A non-calendar fiscal year: each fiscal year starts on July 1.
    config()->set('larabill.fiscal_year.start_month', 7);
    config()->set('larabill.fiscal_year.start_day', 1);

    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);

    // June 30, 2027 is CALENDAR year 2027 but CONFIGURED fiscal year 2026.
    // The v7-drafted `year(invoice_date)` rule (round 1, V4) would have
    // stamped 2027 and rejected a valid boundary-date invoice.
    $instant = Carbon::create(2027, 6, 30, 23, 59, 59);
    \Carbon\Carbon::setTestNow($instant);

    $issued = $service->issue($draft);

    expect($issued->fiscal_year)->toBe(2026)
        ->and($issued->fiscal_year)->not->toBe($issued->invoice_date->year)
        ->and($issued->fiscal_year)->toBe(RegionalContext::getFiscalYear($instant))
        ->and($issued->invoice_date->toDateString())->toBe('2027-06-30');

    \Carbon\Carbon::setTestNow();
});

it('crosses a midnight boundary coherently: the instant, not a later now(), governs the stamps', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);

    // issue() captures its instant up front; the test pins the coherence by
    // freezing time so every internal now() agrees.
    \Carbon\Carbon::setTestNow('2026-12-31 23:59:59');

    $issued = $service->issue($draft);

    expect($issued->fiscal_year)->toBe(2026)
        ->and($issued->invoice_date->toDateString())->toBe('2026-12-31')
        ->and($issued->issued_at->format('YmdHis'))->toBe('20261231235959')
        ->and($issued->fiscal_year)->toBe(RegionalContext::getFiscalYear($issued->issued_at));

    \Carbon\Carbon::setTestNow();
});

it('re-runs the identity seam on a conversion-born document and blocks critical changes (§6 step 3)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    $result = $service->convertProformaToInvoice($proforma, ['on_changes' => 'ignore']);

    // The issuer's tax id changes CRITICALLY between conversion and issuance.
    $config = CompanyFiscalConfig::getActive();
    $config->update(['tax_id' => 'B99999999']);

    expect(fn () => $service->issue($result->invoice))
        ->toThrow(FiscalConfigChangedException::class);

    // The document stayed PREPARED — retryable after remediation.
    expect($result->invoice->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($result->invoice->fresh()->fiscal_number)->toBeNull();
});

it('refuses a document missing its fiscal content typed (§6 step 2)', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice(['billable_user_id' => TestCase::USER_UUID_1, 'items' => [], 'status' => 'draft']);

    // A hand-made row without snapshots can never pass coherence; simulate
    // the missing-issuer-config leg of step 2 through config withdrawal.
    CompanyFiscalConfig::query()->update(['is_active' => false]);

    expect(fn () => $service->issue($draft))
        ->toThrow(InvoiceNotIssuableException::class, 'CompanyFiscalConfig');
});

it('resumes an unnumbered DRAFT after a phase-2 failure, still burning exactly one number', function () {
    $service = app(InvoiceService::class);
    $draft   = $service->createInvoice([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);

    // Simulate a transient phase-2 failure: make issuance impossible, retry
    // after the transient window by issuing. The draft survives the failure
    // (§7.1: the customer's document intent is durable).
    CompanyFiscalConfig::query()->update(['is_active' => false]);

    expect(fn () => $service->issue($draft))->toThrow(InvoiceNotIssuableException::class);
    expect($draft->fresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($draft->fresh()->fiscal_number)->toBeNull();

    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $issued = $service->issue($draft);

    expect($issued->fiscal_number)->toBe('FAC-'.now()->year.'-000001')
        ->and($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED);
});

it('returns the typed conversion result from the full convertAndIssue chain (§3.3 compatibility)', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => TestCase::USER_UUID_1,
        'items'            => [['description' => 'Line', 'quantity' => 100, 'base_price' => 2500]],
    ]);

    $result = $service->convertAndIssue($proforma, ['on_changes' => 'warn']);

    expect($result)->toBeInstanceOf(ProformaConversionResult::class)
        ->and($result->invoice->fiscal_number)->not->toBeNull()
        ->and($result->invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED);
});
