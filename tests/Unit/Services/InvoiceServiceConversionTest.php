<?php

declare(strict_types=1);

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

it('links the final invoice back to its proforma through the canonical proforma_id', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $invoice = $service->convertProformaToInvoice($proforma);

    expect((string) $invoice->proforma_id)->toBe((string) $proforma->id)
        ->and($invoice->proforma->is($proforma))->toBeTrue();
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

    $invoice = $service->convertProformaToInvoice($proforma->fresh());

    $line = $invoice->items->first();
    expect($line->taxable_amount->unscaledValue())->toBe(10000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(2100)
        ->and($line->total_amount->unscaledValue())->toBe(12100)
        ->and($line->taxes_applied)->toBe($frozenTaxes)
        ->and($invoice->total_tax_amount->unscaledValue())->toBe(2100)
        ->and($invoice->total_amount->unscaledValue())->toBe(12100);
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

    $invoice = $service->convertProformaToInvoice($proforma->fresh());

    $line = $invoice->items->first();
    expect($line->item_type)->toBe(ItemType::SERVICE)
        ->and($line->internal_code)->toBe('SKU-42')
        ->and((int) $line->unit_measure_id)->toBe((int) $unit->id)
        ->and($line->service_date_from->toDateString())->toBe('2026-07-01')
        ->and($line->service_date_to->toDateString())->toBe('2026-07-31')
        ->and($line->metadata)->toBe(['source' => 'contract-7'])
        ->and($line->quantity->unscaledValue())->toBe(200)
        ->and($line->unit_price->unscaledValue())->toBe(5000);
});

it('carries the proforma service date into the final invoice, feeding the PDF operation-date row', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    // The operation date the proforma declared (RD 1619/2012 art. 6.1.f/i).
    $proforma->update(['service_date' => '2026-06-15']);

    $invoice = $service->convertProformaToInvoice($proforma->fresh());

    expect($invoice->service_date?->toDateString())->toBe('2026-06-15');

    // The PDF machinery (AID-442) finally has its datum: the row prints
    // because the operation date differs from the conversion-day invoice_date.
    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    expect($prepare->invoke($engine, $invoice->fresh(), null, false)['operation_date'])->toBe('15/06/2026');
});

it('leaves service_date null at conversion when the proforma declared none', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $invoice = $service->convertProformaToInvoice($proforma);

    // Documented rule (AID-559): no source datum → null, never an invented
    // date — the PDF operation-date row simply does not print.
    expect($invoice->service_date)->toBeNull();
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
 * art. 6 — e.g. Castris's `AAICHA`) can now govern the final invoice's series
 * at conversion, instead of the manual proforma→invoice rebuild AID-576
 * reconciles away. The option flows into createInvoice's `series`, which
 * InvoiceSeriesResolver already resolves; defaults stay untouched.
 */
it('numbers the final invoice on a series requested via the conversion option', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $invoice = $service->convertProformaToInvoice($proforma, ['series' => 'AAICHA']);

    // The requested series governs the prefix (distinct from the installation
    // default FAC) and drives the correlative on that virgin series.
    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->prefix)->toBe('AAICHA')
        ->and($invoice->series_number)->toBe(1);
});

it('numbers the final invoice on the resolver default when no series option is given', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    // Regression pin: the default path must stay exactly as before AID-576 —
    // omitted option → InvoiceSeriesResolver default for INVOICE (FAC).
    $invoice = $service->convertProformaToInvoice($proforma);

    expect($invoice->prefix)->toBe('FAC');
});

it('composes the series option with idempotency, minting no extra number on a repeat conversion', function () {
    $service  = app(InvoiceService::class);
    $proforma = $service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    $first = $service->convertProformaToInvoice($proforma, ['series' => 'AAICHA']);

    // Second conversion of the same proforma re-reads the committed state under
    // lock and returns the first invoice — it must NOT consume another AAICHA
    // number (the idempotency guard sits before any numbering).
    $second = $service->convertProformaToInvoice($proforma, ['series' => 'AAICHA']);

    expect($second->id)->toBe($first->id)
        ->and($second->prefix)->toBe('AAICHA')
        ->and(Invoice::where('prefix', 'AAICHA')->count())->toBe(1);
});
