<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\PDFConnectorInterface;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\MissingFiscalVerificationQrException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;

// AID-508/AID-328: getCompanyData() now reads the invoice's frozen issuer
// snapshot instead of a hardcoded fantasy. Without an active
// CompanyFiscalConfig, the templates' header block crashes on a missing key —
// makeQrInvoice() below explicitly snapshots against it (Invoice::boot()'s
// auto-snapshot skips PROFORMA invoices, several of which render here too).
beforeEach(function () {
    CompanyFiscalConfig::factory()->create();
});

it('defaults require_fiscal_verification_qr to false', function () {
    expect(config('larabill.pdf.require_fiscal_verification_qr'))->toBeFalse();
});

it('builds a message naming the invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'CASTRIS-2026-000042',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    $exception = MissingFiscalVerificationQrException::forInvoice($invoice);

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('CASTRIS-2026-000042');
});

function makeQrInvoice(InvoiceSerieType $serie, bool $registered, InvoiceStatus $status = InvoiceStatus::SENT): Invoice
{
    $invoice = Invoice::factory()->create([
        'fiscal_number'          => 'QR-AXES',
        'serie'                  => $serie->value,
        'status'                 => $status->value,
        'user_id'                => TestCase::USER_UUID_1,
        'fiscal_verification_id' => $registered ? 'REG-1' : null,
        'fiscal_verified_at'     => $registered ? now() : null,
    ]);

    // Invoice::boot()'s auto-snapshot skips PROFORMA (ADR-001) — snapshot
    // explicitly here so both series carry an issuer identity to print.
    $invoice->snapshotFiscalConfigs();
    $invoice->save();

    return $invoice->fresh();
}

it('includes the QR for every fiscal serie once registered', function (InvoiceSerieType $serie) {
    expect(makeQrInvoice($serie, registered: true)->shouldIncludeQR())->toBeTrue();
})->with([
    'invoice'       => InvoiceSerieType::INVOICE,
    'simplified'    => InvoiceSerieType::SIMPLIFIED,   // AID-508: was excluded; a ticket is a fiscal document
    'rectificative' => InvoiceSerieType::RECTIFICATIVE,
]);

it('never includes the QR for a proforma, registered or not', function (bool $registered) {
    expect(makeQrInvoice(InvoiceSerieType::PROFORMA, $registered)->shouldIncludeQR())->toBeFalse();
})->with([true, false]);

it('does not include the QR for a fiscal invoice without a record', function () {
    expect(makeQrInvoice(InvoiceSerieType::INVOICE, registered: false)->shouldIncludeQR())->toBeFalse();
});

it('ignores delivery and payment status entirely', function (InvoiceStatus $status) {
    expect(makeQrInvoice(InvoiceSerieType::INVOICE, registered: true, status: $status)->shouldIncludeQR())->toBeTrue();
})->with([
    'sent'    => InvoiceStatus::SENT,
    'paid'    => InvoiceStatus::PAID,
    'overdue' => InvoiceStatus::OVERDUE,
    'draft'   => InvoiceStatus::DRAFT,
]);

it('fails explicitly when strict mode meets a fiscal invoice with no record', function () {
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('fiscal verification QR')
        ->and($invoice->getPDFPath())->toBeNull();
});

it('fails explicitly when strict mode meets a registered invoice whose QR is unusable', function (string $qr) {
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: true);
    $invoice->update(['fiscal_verification_qr' => $qr]);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse()
        ->and($invoice->getPDFPath())->toBeNull();
})->with([
    'bare url'       => 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B1',
    'invalid base64' => 'data:image/png;base64,not!valid!',
    'malformed svg'  => '<svg xmlns="http://www.w3.org/2000/svg"><rect',
]);

it('fails explicitly when the QR is present but the record is incoherent', function () {
    // The blind spot of checking only the QR: a valid SVG with no registration ids.
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);
    $invoice->update(['fiscal_verification_qr' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>']);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse();
});

it('renders without the tax block when the contract is not required', function () {
    config()->set('larabill.pdf.require_fiscal_verification_qr', false);
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeTrue();
});

it('exempts a proforma from strict mode', function () {
    config()->set('larabill.pdf.require_fiscal_verification_qr', true);
    $invoice = makeQrInvoice(InvoiceSerieType::PROFORMA, registered: false);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeTrue();
});

it('never asks a connector to produce the QR', function () {
    // The spy that proves 4b does what it says: whatever the invoice's state, the
    // pipeline reads the fiscal record and never calls a connector to fabricate one.
    // makeQrInvoice() hardcodes fiscal_number to 'QR-AXES' (fine for the single-invoice
    // tests above); three live rows in one test would collide on the unique index, so
    // each is deleted before the next is created.
    //
    // The spy must stub isAvailable() (unstubbed, it returns false — Mockery's
    // type-aware default for `bool` — so getConnector() would never select it and
    // generatePDF() would fail early with "No suitable PDF connector found",
    // making shouldNotHaveReceived('generateQR') pass without exercising anything).
    $connector = Mockery::spy(PDFConnectorInterface::class);
    $connector->shouldReceive('isAvailable')->andReturn(true);
    $connector->shouldReceive('getConnectorType')->andReturn('local');
    $service = new PDFService;
    $service->registerConnector('local', $connector);

    $registered = makeQrInvoice(InvoiceSerieType::INVOICE, registered: true);
    $result     = $service->generatePDF($registered);
    $registered->delete();

    // Proves the pipeline actually ran (not a vacuous early failure): a real PDF
    // was rendered through the spy connector.
    expect($result['success'])->toBeTrue();

    $unregistered = makeQrInvoice(InvoiceSerieType::INVOICE, registered: false);
    $service->generatePDF($unregistered);
    $unregistered->delete();

    $service->generatePDF(makeQrInvoice(InvoiceSerieType::PROFORMA, registered: false));

    $connector->shouldNotHaveReceived('generateQR');
});

function renderQrTemplate(string $template, Invoice $invoice, ?array $qrData): string
{
    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    $render  = new ReflectionMethod($engine, 'renderTemplate');

    return $render->invoke($engine, $template, $prepare->invoke($engine, $invoice, $qrData, $qrData !== null));
}

it('renders the QR as an image with its label and legal size', function (string $template) {
    $qr = ['qr_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>', 'qr_code' => 'x'];

    $html = renderQrTemplate($template, makeQrInvoice(InvoiceSerieType::INVOICE, registered: true), $qr);

    // AEAT QR spec v0.4.7 art. 20-21: 30x30..40x40 mm, preceded by «QR tributario:».
    expect($html)->toContain('QR tributario')
        ->and($html)->toContain('35mm')
        ->and($html)->toContain('<svg')
        ->and($html)->not->toContain('&lt;svg')   // escaped SVG dumped as text
        ->and($html)->not->toContain('QR_CODE');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('renders the QR as a PNG image with its label and legal size', function (string $template) {
    // spec §6: the SVG branch is covered above; the PNG branch (elseif in
    // fiscal.blade.php, else in the other four) needs its own coverage.
    $png = 'data:image/png;base64,'.base64_encode(
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true)
    );
    $qr = ['qr_png' => $png, 'qr_code' => 'x'];

    $html = renderQrTemplate($template, makeQrInvoice(InvoiceSerieType::INVOICE, registered: true), $qr);

    // AEAT QR spec v0.4.7 art. 20-21: 30x30..40x40 mm, preceded by «QR tributario:».
    expect($html)->toContain('QR tributario')
        ->and($html)->toContain('35mm')
        ->and($html)->toContain('<img')
        ->and($html)->toContain($png)
        ->and($html)->not->toContain('<svg')
        ->and($html)->not->toContain('QR_CODE');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('never renders a QR on a proforma, even when one is injected', function () {
    $qr = ['qr_svg' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', 'qr_code' => 'x'];

    $html = renderQrTemplate('larabill::pdf.invoice.proforma', makeQrInvoice(InvoiceSerieType::PROFORMA, registered: false), $qr);

    expect($html)->not->toContain('QR tributario')
        ->and($html)->not->toContain('<svg')
        ->and($html)->not->toContain('QR_CODE');
});

it('prints no tax block at all when there is no QR', function (string $template) {
    $html = renderQrTemplate($template, makeQrInvoice(InvoiceSerieType::INVOICE, registered: false), null);

    expect($html)->not->toContain('QR tributario')
        ->and($html)->not->toContain('QR_CODE');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
]);

it('prints no tax block when a registered invoice has a corrupted QR image', function () {
    // AID-508 regression: fiscal.blade.php gated the QR section with
    // `isset($qr_data)`, not the canonical `!empty($qr_data['qr_svg']) ||
    // !empty($qr_data['qr_png'])` used by the other four fiscal templates. A
    // registered, fiscal invoice whose persisted fiscal_verification_qr is
    // corrupted (FiscalQrImage::classify() -> null) makes PDFService build the
    // null-QR success array (no qr_svg/qr_png keys) — in non-strict mode that
    // array still reaches the template as $qr_data, so `isset($qr_data)` was
    // true and fiscal.blade.php rendered an EMPTY box under «QR tributario:»
    // while the other four correctly rendered nothing.
    $invoice = makeQrInvoice(InvoiceSerieType::INVOICE, registered: true);
    $invoice->update(['fiscal_verification_qr' => 'data:image/png;base64,not!valid!']);

    $nullQr = ['success' => true, 'qr_code' => null, 'qr_url' => null, 'qr_data' => []];

    $html = renderQrTemplate('larabill::pdf.invoice.fiscal', $invoice->fresh(), $nullQr);

    expect($html)->not->toContain('QR tributario');
});
