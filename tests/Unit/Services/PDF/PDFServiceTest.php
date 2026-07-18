<?php

declare(strict_types=1);
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DefaultPDFConnector;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pdfService = new PDFService;

    // AID-508/AID-328: getCompanyData() now reads the invoice's frozen issuer
    // snapshot instead of a hardcoded fantasy. Without an active
    // CompanyFiscalConfig for Invoice::boot()'s creating() hook to auto-snapshot
    // against, template rendering crashes on a missing 'name' key.
    CompanyFiscalConfig::factory()->create();
});

it('can be instantiated', function () {
    expect($this->pdfService)->toBeInstanceOf(PDFService::class);
});

it('accepts an injected DomPDF engine instead of hard-wiring one (AID-391)', function () {
    $engine = new class([]) extends DomPDFService {};

    $service = new PDFService([], null, $engine);

    expect($service->getDomPDFService())->toBe($engine);
});

it('persists PDFs through the File facade (AID-391)', function () {
    $engine = new DomPDFService([]);

    // AID-508: savePDF() now composes the name via Invoice::pdfFilename()
    // (getInvoiceType() → $this->serie->label()), the same path getPDFPath()
    // reads — so a bare invoice needs its serie set, unlike before when
    // savePDF() read the nonexistent $invoice->type and never touched serie.
    $invoice        = new Invoice;
    $invoice->id    = (string) Str::uuid7();
    $invoice->serie = InvoiceSerieType::INVOICE;

    $save = new ReflectionMethod($engine, 'savePDF');
    $save->setAccessible(true);

    // savePDF() always writes under the single private root (AID-508); the
    // write itself goes through File::put.
    $path = $save->invoke($engine, $invoice, '%PDF-fake-content');

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toBe('%PDF-fake-content');

    File::delete($path);
});

it('can get available connectors', function () {
    $connectors = $this->pdfService->getAvailableConnectors();

    expect($connectors)->toBeArray();
    expect($connectors)->toHaveKey('local');
    expect($connectors['local']['type'])->toBe('local');
});

it('can get default connector', function () {
    $connector = $this->pdfService->getConnector();

    expect($connector)->toBeInstanceOf(DefaultPDFConnector::class);
    expect($connector->getConnectorType())->toBe('local');
});

it('can get specific connector', function () {
    $connector = $this->pdfService->getConnector('local');

    expect($connector)->toBeInstanceOf(DefaultPDFConnector::class);
    expect($connector->getConnectorType())->toBe('local');
});

it('returns null for non-existent connector', function () {
    $connector = $this->pdfService->getConnector('non-existent');

    expect($connector)->toBeNull();
});

it('can get configuration', function () {
    $config = $this->pdfService->getConfiguration();

    expect($config)->toBeArray();
    expect($config)->toHaveKey('default_connector');
    // fallback_to_local removed (AID-508): the frontier no longer retries with
    // another connector after a failure — see PDFServiceTest's frontier tests.
    expect($config)->not->toHaveKey('fallback_to_local');
    expect($config['default_connector'])->toBe('local');
});

it('can update configuration', function () {
    $newConfig = ['cache_ttl' => 7200];
    $this->pdfService->updateConfiguration($newConfig);

    $config = $this->pdfService->getConfiguration();
    expect($config['cache_ttl'])->toBe(7200);
});

it('can register new connector', function () {
    $mockConnector = new DefaultPDFConnector;
    $this->pdfService->registerConnector('test', $mockConnector);

    $connector = $this->pdfService->getConnector('test');
    expect($connector)->toBeInstanceOf(DefaultPDFConnector::class);
});

it('can generate PDF for invoice', function () {
    // Create a test invoice with factory
    $invoice = Invoice::factory()->create([
        'fiscal_number'     => 'TEST-001',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'status'            => InvoiceStatus::DRAFT->value,
        'user_id'           => TestCase::USER_UUID_1,
        'taxable_amount'    => cents((int) 100.0),
        'total_tax_amount'  => cents((int) 21.0),
        'total_amount'      => cents((int) 121.0),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('qr_data');
    expect($result['connector_used'])->toBe('local');
});

it('uses the persisted fiscal verification QR when generating fiscal PDFs', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'                 => 'TEST-FISCAL-QR',
        'serie'                         => InvoiceSerieType::INVOICE->value,
        'status'                        => InvoiceStatus::DRAFT->value,
        'user_id'                       => TestCase::USER_UUID_1,
        'taxable_amount'                => cents(10000),
        'total_tax_amount'              => cents(2100),
        'total_amount'                  => cents(12100),
        'fiscal_verification_qr'        => '<svg data-testid="verifactu-qr"></svg>',
        'fiscal_verification_metadata'  => [
            'qr_url' => 'https://prewww2.aeat.es/qr?id=REG-000001',
        ],
        // AID-508: the QR alone is not enough — the frontier requires a coherent
        // registration too (fiscal_verification_id + fiscal_verified_at).
        'fiscal_verification_id'        => 'REG-000001',
        'fiscal_verified_at'            => now(),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result['success'])->toBeTrue()
        ->and($result['qr_data']['source'])->toBe('fiscal_verification')
        // AID-537: the svg reaches the template normalized to the 35mm box.
        ->and($result['qr_data']['qr_svg'])->toContain('data-testid="verifactu-qr"')
        ->and($result['qr_data']['qr_svg'])->toContain('width="132"')
        ->and($result['qr_data']['qr_url'])->toBe('https://prewww2.aeat.es/qr?id=REG-000001');
});

it('can handle PDF generation errors gracefully', function () {
    // Create an invoice with minimal fields
    $invoice = Invoice::factory()->create([
        'fiscal_number'        => 'TEST-ERROR',
        'serie'                => InvoiceSerieType::INVOICE->value,
        'status'               => InvoiceStatus::DRAFT->value,
        'user_id'              => TestCase::USER_UUID_1,
        'taxable_amount'       => cents(10000),
        'total_tax_amount'     => cents(2100),
        'total_amount'         => cents(12100),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue(); // Service handles missing fields gracefully
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('connector_used');
});

it('can cache PDF results', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'          => 'TEST-002',
        'serie'                  => InvoiceSerieType::INVOICE->value,
        'status'                 => InvoiceStatus::DRAFT->value,
        'user_id'                => TestCase::USER_UUID_1,
        'taxable_amount'         => cents((int) 100.0),
        'total_tax_amount'       => cents((int) 21.0),
        'total_amount'           => cents((int) 121.0),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result['success'])->toBeTrue();

    // Cache is disabled by default in tests (no cache repository provided)
    $cached = $this->pdfService->getCachedPDFResult($invoice);
    expect($cached)->toBeNull();
});

it('can clear PDF cache', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'        => 'TEST-003',
        'serie'                => InvoiceSerieType::INVOICE->value,
        'status'               => InvoiceStatus::DRAFT->value,
        'user_id'              => TestCase::USER_UUID_1,
        'taxable_amount'       => cents(10000),
        'total_tax_amount'     => cents(2100),
        'total_amount'         => cents(12100),
    ]);

    $this->pdfService->generatePDF($invoice);
    $this->pdfService->clearPDFCache($invoice);

    $cached = $this->pdfService->getCachedPDFResult($invoice);
    expect($cached)->toBeNull();
});

it('logs the original exception before translating it into the failure contract', function () {
    Log::spy();
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FRONTIER-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
        'template_name' => null,
    ]);
    // Force a render failure: point the invoice at a view that does not exist.
    View::shouldReceive('make')->andThrow(new RuntimeException('boom from the depths'));

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('boom from the depths');

    // The frontier is the subsystem's single logger (AID-535): the render
    // level no longer logs, so this is the ONLY error line for the failure.
    Log::shouldHaveReceived('error')
        ->with('larabill: invoice PDF generation failed', Mockery::any())
        ->once();
});

it('translates a raw Error from the engine into an explicit failure (AID-535)', function () {
    $engine = new class([]) extends DomPDFService
    {
        /**
         * @param  array<string, mixed>|null  $qrData
         * @return array<string, mixed>
         */
        public function generatePDF(Invoice $invoice, ?array $qrData = null): array
        {
            throw new Error('engine blew up with a raw Error');
        }
    };

    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'THROWABLE-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    // The frontier is the single translator: \Error/TypeError must reach the
    // consumer as ['success' => false], never as an uncaught throwable.
    $result = (new PDFService([], null, $engine))->generatePDF($invoice);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('raw Error');
});

it('logs a failed generation exactly once, at the frontier (AID-535)', function () {
    Log::spy();

    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'SINGLELOG-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);
    View::shouldReceive('make')->andThrow(new RuntimeException('render exploded once'));

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeFalse();

    // One failure, one log line — the frontier's. The render level no longer
    // logs: the failing view's name travels inside the exception itself.
    Log::shouldHaveReceived('error')->once();
});

it('does not retry with the local connector when generation fails', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FRONTIER-2',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);
    View::shouldReceive('make')->andThrow(new RuntimeException('render exploded'));

    $result = (new PDFService)->generatePDF($invoice);

    // The old fallback_to_local retried and could report success on a fabricated path.
    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('render exploded');
});

it('finds the file it just wrote, so the PDF is not regenerated on every download', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'PATH-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    $result = (new PDFService)->generatePDF($invoice);

    // savePDF() used $invoice->type (a column that does not exist) → invoice_<id>_.pdf
    // while getPDFPath() used getInvoiceType() → invoice_<id>_invoice.pdf. They never
    // matched, so the consumer's controller regenerated the PDF on every download.
    expect($result['success'])->toBeTrue()
        ->and($invoice->getPDFPath())->not->toBeNull()
        ->and($invoice->getPDFPath())->toBe($result['pdf_path']);
});

it('publishes no url for a private invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'PATH-2',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['pdf_url'])->toBeNull()
        ->and($invoice->getPDFUrl())->toBeNull()
        ->and(json_encode($result))->not->toContain('storage/invoices');
});
