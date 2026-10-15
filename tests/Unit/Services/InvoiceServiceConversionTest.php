<?php

declare(strict_types=1);

use AichaDigital\Larabill\DTO\ProformaConversionResult;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UnitMeasure;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-554 (D1) + ADR-016 §9 (PR-2) — sequential contract of
 * convertProformaToInvoice().
 *
 * The pins freeze the sequential behaviour the row-lock hardening must
 * preserve, re-pointed to the PREPARED contract: the conversion consumes NO
 * correlative, returns the TYPED result (the Invoice|array union is gone),
 * and the proforma reads CONVERTING + immutable with the mirror written at
 * conversion. The concurrency proof itself lives in
 * tests/Concurrency/ProformaConversionConcurrencyTest.php (fork-based, MySQL,
 * RUN_CONCURRENCY_IT=1).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
});

it('converts a proforma into a PREPARED document and freezes the proforma as CONVERTING', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $result = $service->convertProformaToInvoice($proforma);

    // Typed command in, typed result out (§9): the Invoice|array union died.
    expect($result)->toBeInstanceOf(ProformaConversionResult::class)
        ->and($result->invoice)->toBeInstanceOf(Invoice::class)
        ->and($result->invoice->serie)->toBe(InvoiceSerieType::INVOICE)
        ->and($result->invoice->id)->not->toBe($proforma->id)
        ->and($result->warnings)->toBe([]);

    // §9: the document births PREPARED — unnumbered, submission NULL.
    expect($result->invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($result->invoice->fiscal_number)->toBeNull()
        ->and($result->invoice->fiscal_submission_status)->toBeNull();

    // §9: the proforma freezes at conversion — CONVERTING (not CONVERTED: the
    // CONVERTED stamp moved to issue()), immutable, mirror written.
    $frozen = $proforma->fresh();
    expect($frozen->status)->toBe(InvoiceStatus::CONVERTING)
        ->and($frozen->is_immutable)->toBeTrue()
        ->and($frozen->converted_invoice_id)->toBe($result->invoice->id)
        ->and($frozen->converted_at)->not->toBeNull();
});

it('issues the PREPARED document into the full ISSUED contract when the caller goes on', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $result   = $service->convertProformaToInvoice($proforma);
    $issued   = $service->issue($result->invoice);

    // §6: numbered, doc ISSUED, submission stamped, collection axis DRAFT.
    expect($issued->fiscal_number)->not->toBeNull()
        ->and($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->fiscal_submission_status)->not->toBeNull()
        ->and($issued->status)->toBe(InvoiceStatus::DRAFT);

    // §5.2/§6 step 8: the proforma's CONVERTING stamp becomes CONVERTED at
    // issuance, never at conversion.
    expect($proforma->fresh()->status)->toBe(InvoiceStatus::CONVERTED);
});

it('links the prepared document back to its proforma through the canonical proforma_id', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $result = $service->convertProformaToInvoice($proforma);

    expect((string) $result->invoice->proforma_id)->toBe((string) $proforma->id)
        ->and($result->invoice->proforma->is($proforma))->toBeTrue();
});

it('returns the already-prepared document when converting the same proforma again', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $first  = $service->convertProformaToInvoice($proforma);
    $second = $service->convertProformaToInvoice($proforma);

    // Deliberately pass the now-stale instance: the method must re-read the
    // committed state inside its transaction, not trust the caller's copy.
    expect($second->invoice->id)->toBe($first->invoice->id)
        ->and(Invoice::where('serie', InvoiceSerieType::INVOICE->value)->count())->toBe(1);
});

it('preserves the frozen line amounts at conversion, never recalculating against the live catalog', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'Frozen line', 'quantity' => 100, 'base_price' => 10000]],
    ]);

    // Freeze distinctive amounts on the proforma line — the figures the
    // customer accepted (ADR-001). Converting must carry them VERBATIM;
    // recalculating against the live tax catalog would yield different ones.
    // (rate 21.5: fraction survives the JSON round-trip of the array cast —
    // 21.0 would collapse to int 21 on the proforma itself already.)
    $frozenTaxes = [['name' => 'IVA', 'rate' => 21.5, 'amount' => 2100]];
    $proforma->items->first()->forceFill([
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'taxes_applied'    => $frozenTaxes,
        'total_amount'     => cents(12100),
    ])->save();

    $result = $service->convertProformaToInvoice($proforma->fresh());

    $line = $result->invoice->items->first();
    expect($line->taxable_amount->unscaledValue())->toBe(10000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(2100)
        ->and($line->total_amount->unscaledValue())->toBe(12100)
        ->and($line->taxes_applied)->toBe($frozenTaxes)
        ->and($result->invoice->total_tax_amount->unscaledValue())->toBe(2100)
        ->and($result->invoice->total_amount->unscaledValue())->toBe(12100);
});

it('clones the full proforma line at conversion, losing no fields', function () {
    $service = app(InvoiceService::class);
    $unit    = UnitMeasure::create(['code' => 'hour', 'symbol' => 'h', 'name' => 'Hour']);

    $proforma = $service->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'Full line', 'quantity' => 200, 'base_price' => 5000]],
    ]);

    $proforma->items->first()->forceFill([
        'item_type'         => ItemType::SERVICE,
        'internal_code'     => 'SKU-42',
        'unit_measure_id'   => $unit->id,
        'service_date_from' => '2026-07-01',
        'service_date_to'   => '2026-07-31',
        'metadata'          => ['source' => 'contract-7'],
    ])->save();

    $result = $service->convertProformaToInvoice($proforma->fresh());

    $line = $result->invoice->items->first();
    expect($line->item_type)->toBe(ItemType::SERVICE)
        ->and($line->internal_code)->toBe('SKU-42')
        ->and((int) $line->unit_measure_id)->toBe((int) $unit->id)
        ->and($line->service_date_from->toDateString())->toBe('2026-07-01')
        ->and($line->service_date_to->toDateString())->toBe('2026-07-31')
        ->and($line->metadata)->toBe(['source' => 'contract-7'])
        ->and($line->quantity->unscaledValue())->toBe(200)
        ->and($line->unit_price->unscaledValue())->toBe(5000);
});

it('carries the proforma service date into the prepared document, feeding the PDF operation-date row', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    // The operation date the proforma declared (RD 1619/2012 art. 6.1.f/i).
    $proforma->update(['service_date' => '2026-06-15']);

    $result = $service->convertProformaToInvoice($proforma->fresh());

    expect($result->invoice->service_date?->toDateString())->toBe('2026-06-15');

    // The PDF machinery (AID-442) finally has its datum: the row prints
    // because the operation date differs from the issuance-day invoice_date.
    // The prepared document has no invoice_date yet (§9) — the row
    // materialises once the document is issued, from the SAME service_date.
    $issued  = $service->issue($result->invoice);
    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    expect($prepare->invoke($engine, $issued->fresh(), null, false)['operation_date'])->toBe('15/06/2026');
});

it('leaves service_date null at conversion when the proforma declared none', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $result = $service->convertProformaToInvoice($proforma);

    // Documented rule (AID-559): no source datum → null, never an invented
    // date — the PDF operation-date row simply does not print.
    expect($result->invoice->service_date)->toBeNull();
});

it('persists a service_date passed explicitly to createInvoice', function () {
    $service = app(InvoiceService::class);

    $invoice = $service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
        'service_date'     => '2026-05-01',
    ]);

    expect($invoice->service_date?->toDateString())->toBe('2026-05-01');
});

it('rejects converting an invoice that is not a proforma', function () {
    $service = app(InvoiceService::class);
    $invoice = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);

    expect(fn () => $service->convertProformaToInvoice($invoice))
        ->toThrow(InvalidArgumentException::class, 'not a proforma');
});

/*
 * AID-576 (v6.7.0) — the conversion forwards a per-call fiscal series.
 *
 * A consumer running multiple series for the same fiscal type (RD 1619/2012
 * art. 6 — e.g. Castris's `AAICHA`) can now govern the prepared document's
 * series at conversion; the correlative waits for issue() on that same
 * series (§8.1: the prefix is stamped at creation, the number at issuance).
 */
it('stamps the series requested via the conversion option and issues on it', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $result = $service->convertProformaToInvoice($proforma, ['series' => 'AAICHA']);

    // The requested series governs the PREFIX at creation (distinct from the
    // installation default FAC); NO correlative is consumed (§9).
    expect($result->invoice->prefix)->toBe('AAICHA')
        ->and($result->invoice->series_number)->toBeNull();

    // issuance burns exactly one number ON THAT SERIES.
    $issued = $service->issue($result->invoice);
    expect($issued->prefix)->toBe('AAICHA')
        ->and($issued->series_number)->toBe(1);
});

it('stamps the resolver default when no series option is given', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    // Regression pin: the default path must stay exactly as before AID-576 —
    // omitted option → InvoiceSeriesResolver default for INVOICE (FAC).
    $result = $service->convertProformaToInvoice($proforma);

    expect($result->invoice->prefix)->toBe('FAC');
});

it('composes the series option with idempotency, minting no extra document on a repeat conversion', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $first = $service->convertProformaToInvoice($proforma, ['series' => 'AAICHA']);

    // Second conversion of the same proforma re-reads the committed state under
    // lock and returns the first document — it must NOT mint a second
    // AAICHA document (the idempotency guard sits before any creation).
    $second = $service->convertProformaToInvoice($proforma, ['series' => 'AAICHA']);

    expect($second->invoice->id)->toBe($first->invoice->id)
        ->and($second->invoice->prefix)->toBe('AAICHA')
        ->and(Invoice::where('prefix', 'AAICHA')->count())->toBe(1);
});
