<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceTemplate;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\View;

/**
 * AID-502 (ADR-011, D2a): the post-render guard wired into the real render
 * path. A consumer template resolved through the registry (D1) that drops
 * mandatory fiscal content must surface as an explicit failure at the
 * frontier — never as a plausible non-compliant PDF. Proformas and
 * installations that disabled the guard keep rendering.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create();

    View::addNamespace('aid502-fixtures', __DIR__.'/fixtures/aid502');
});

function registerConsumerTemplate(string $name, string $type, string $view): void
{
    InvoiceTemplate::create([
        'name'          => $name,
        'display_name'  => ucfirst($name),
        'type'          => $type,
        'template_path' => $view,
        'is_default'    => false,
        'is_active'     => true,
    ]);
}

function guardedInvoice(InvoiceSerieType $serie, string $templateName): Invoice
{
    return Invoice::factory()->create([
        'fiscal_number'    => 'GUARD-'.strtoupper($templateName).'-1',
        'serie'            => $serie->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'template_name'    => $templateName,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
    ]);
}

it('fails loud when a consumer template drops mandatory fiscal content', function () {
    registerConsumerTemplate('broken', 'fiscal', 'aid502-fixtures::broken');

    $result = (new PDFService)->generatePDF(guardedInvoice(InvoiceSerieType::INVOICE, 'broken'));

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('missing mandatory fiscal content')
        ->and($result['error'])->toContain('fiscal_number');
});

it('renders a radically restyled but conformant consumer template', function () {
    registerConsumerTemplate('restyled', 'fiscal', 'aid502-fixtures::restyled');

    $result = (new PDFService)->generatePDF(guardedInvoice(InvoiceSerieType::INVOICE, 'restyled'));

    expect($result['success'])->toBeTrue();
});

it('does not validate proformas — they are not fiscal documents', function () {
    registerConsumerTemplate('broken', 'proforma', 'aid502-fixtures::broken');

    $result = (new PDFService)->generatePDF(guardedInvoice(InvoiceSerieType::PROFORMA, 'broken'));

    expect($result['success'])->toBeTrue();
});

it('lets the installation assume the risk via config', function () {
    config()->set('larabill.pdf.validate_fiscal_content', false);

    registerConsumerTemplate('broken', 'fiscal', 'aid502-fixtures::broken');

    $result = (new PDFService)->generatePDF(guardedInvoice(InvoiceSerieType::INVOICE, 'broken'));

    expect($result['success'])->toBeTrue();
});

it('ships the guard enabled by default', function () {
    expect(config('larabill.pdf.validate_fiscal_content'))->toBeTrue();
});

it('gives guardedInvoice a FISCAL fixture: InvoiceFactory never rolls is_roi_taxed on', function () {
    // Regression guard (AID-576 cleanup) for the nondeterministic failure of
    // "fails loud when a consumer template drops mandatory fiscal content".
    // guardedInvoice() relies on the factory yielding a plain FISCAL invoice.
    // The old random default (faker->boolean(10)) flipped ~10% of these to
    // reverse-charge, which resolveTemplateType() routes to the CONFORMANT
    // package reverse-charge blade — bypassing the broken-template guard and
    // making success=true. The default must stay deterministic (same
    // factory-coin class as AID-508 / AID-558).
    $flags = collect(range(1, 60))->map(fn (): bool => (bool) Invoice::factory()->make()->is_roi_taxed);

    expect($flags->contains(true))->toBeFalse();
});
