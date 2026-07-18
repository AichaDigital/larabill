<?php

declare(strict_types=1);

use AichaDigital\Larabill\Database\Seeders\InvoiceTemplatesSeeder;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\SettingType;
use AichaDigital\Larabill\Enums\TemplateInvoiceType;
use AichaDigital\Larabill\Models\CompanyTemplateSettings;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceTemplate;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use Illuminate\Support\Facades\Log;

/**
 * AID-502 (ADR-011, D1): the template registry finally resolves for fiscal
 * invoices. `invoice_templates.type` speaks the presentation vocabulary
 * (TemplateInvoiceType::registryKey()); lookups used to pass the fiscal serie
 * label ('invoice') instead, so `template_name` was silently ignored and the
 * seeded defaults never applied to any fiscal document.
 */
function domResolveView(Invoice $invoice): string
{
    $ref = new ReflectionMethod(DomPDFService::class, 'getTemplateForInvoice');
    $ref->setAccessible(true);

    return $ref->invoke(new DomPDFService, $invoice);
}

function domTemplateSettings(Invoice $invoice): array
{
    $ref = new ReflectionMethod(DomPDFService::class, 'getTemplateSettings');
    $ref->setAccessible(true);

    return $ref->invoke(new DomPDFService, $invoice);
}

function fiscalInvoiceInMemory(?string $templateName = null): Invoice
{
    $invoice        = new Invoice;
    $invoice->serie = InvoiceSerieType::INVOICE;

    if ($templateName !== null) {
        $invoice->template_name = $templateName;
    }

    return $invoice;
}

it('resolves a named registry template for a fiscal invoice', function () {
    (new InvoiceTemplatesSeeder)->run();

    $view = domResolveView(fiscalInvoiceInMemory('modern'));

    expect($view)->toBe('larabill::pdf.invoice.fiscal-modern');
});

it('lets the registry default row govern the fiscal view', function () {
    (new InvoiceTemplatesSeeder)->run();

    // A consumer that flips its default template expects it honoured — the
    // default row used to contribute settings only, never the view.
    InvoiceTemplate::query()
        ->where('type', 'fiscal')->where('is_default', true)
        ->update(['template_path' => 'larabill::pdf.invoice.fiscal-minimal']);

    $view = domResolveView(fiscalInvoiceInMemory());

    expect($view)->toBe('larabill::pdf.invoice.fiscal-minimal');
});

it('warns and falls back when the requested template name does not exist', function () {
    (new InvoiceTemplatesSeeder)->run();

    Log::spy();

    $view = domResolveView(fiscalInvoiceInMemory('no-such-template'));

    expect($view)->toBe('larabill::pdf.invoice.fiscal');
    Log::shouldHaveReceived('warning')->once();
});

it('falls back to the package views when the registry is not seeded', function () {
    expect(domResolveView(fiscalInvoiceInMemory()))->toBe('larabill::pdf.invoice.fiscal');
});

it('resolves the seeded default settings for a fiscal invoice', function () {
    (new InvoiceTemplatesSeeder)->run();

    $settings = domTemplateSettings(fiscalInvoiceInMemory());

    expect($settings)->toBe([
        'show_qr'            => true,
        'show_legal_notes'   => true,
        'show_payment_terms' => true,
    ]);
});

it('consults the notes of the resolved template type, not always fiscal', function () {
    // The reverse-charge/exempt arms of the settings vocabulary existed but were
    // unreachable: the old string hop only ever produced PROFORMA or FISCAL.
    $invoice                           = fiscalInvoiceInMemory();
    $invoice->is_roi_taxed             = true;
    $invoice->company_fiscal_config_id = 'cfg-1';

    CompanyTemplateSettings::setSetting('cfg-1', SettingType::NOTES, TemplateInvoiceType::FISCAL, 'Fiscal notes');
    CompanyTemplateSettings::setSetting('cfg-1', SettingType::NOTES, TemplateInvoiceType::REVERSE_CHARGE, 'Reverse-charge notes');

    $ref = new ReflectionMethod(DomPDFService::class, 'getInvoiceNotes');
    $ref->setAccessible(true);

    expect($ref->invoke(new DomPDFService, $invoice))->toBe('Reverse-charge notes');
});
