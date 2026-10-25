<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\Larabill\ValueObjects\Allocation;
use Smalot\PdfParser\Parser;

/*
 * AID-962 (spec §6, §12.1): the collection block under the totals in the six
 * PDF templates. It reads the active allocations and splits them into
 * credit-funded (an active APPLIED exit) and directly paid; the proforma
 * carries the block prepared but never fed (§12.1).
 */

/**
 * Render through the real DomPDFService (validator included) forcing a view.
 *
 * @return array<string, mixed>
 */
function aid962GenerateWith(string $view, Invoice $invoice): array
{
    $service = new class($view) extends DomPDFService
    {
        public function __construct(private string $forcedView)
        {
            parent::__construct();
        }

        protected function getTemplateForInvoice(Invoice $invoice): string
        {
            return $this->forcedView;
        }
    };

    return $service->generatePDF($invoice);
}

function aid962PdfText(array $result): string
{
    return (new Parser)->parseFile($result['pdf_path'])->getText();
}

function aid962IssuedInvoice(int $totalCents, int $taxCents): Invoice
{
    $config = CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);

    $invoice = Invoice::factory()->sent()->create([
        'fiscal_number'            => 'COL-'.uniqid(),
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'user_id'                  => TestCase::USER_UUID_1,
        'billable_user_id'         => TestCase::USER_UUID_2,
        'invoice_date'             => '2026-07-16',
        'company_fiscal_config_id' => $config->id,
        'is_immutable'             => false,
        'paid_at'                  => null,
        'due_date'                 => null,
        'is_roi_taxed'             => false,
        'taxable_amount'           => cents($totalCents - $taxCents),
        'total_tax_amount'         => cents($taxCents),
        'total_amount'             => cents($totalCents),
    ]);
    $invoice->snapshotFiscalConfigs();
    $invoice->saveQuietly();

    quietInvoiceItem([
        'invoice_id'       => $invoice->id,
        'description'      => 'Hosting anual Probe',
        'quantity'         => cents(100),
        'unit_price'       => cents($totalCents - $taxCents),
        'taxable_amount'   => cents($totalCents - $taxCents),
        'total_tax_amount' => cents($taxCents),
        'total_amount'     => cents($totalCents),
        'taxes_applied'    => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => $taxCents]],
    ]);

    return $invoice->fresh();
}

/** Invoice 12100: 4000 paid directly, 2100 funded from credit. */
function aid962PartlyCollectedInvoice(): Invoice
{
    $invoice  = aid962IssuedInvoice(12100, 2100);
    $at       = now()->subHour()->startOfSecond();
    $payments = app(PaymentService::class);

    $payments->receive(
        TestCase::USER_UUID_2, cents(4000), 'EUR', $at, PaymentMethod::BankTransfer, null, null,
        [new Allocation($invoice->id, cents(4000))],
    );
    $payments->receive(
        TestCase::USER_UUID_2, cents(2100), 'EUR', $at, PaymentMethod::BankTransfer, null, null,
        [], CreditMovementKind::TOP_UP,
    );
    app(CustomerCredit::class)->apply(TestCase::USER_UUID_2, $invoice, cents(2100), TestCase::USER_UUID_1, $at);

    return $invoice->fresh();
}

beforeEach(function () {
    app()->setLocale('en');
});

it('prints credit applied, amount paid and amount due under the totals', function (string $view) {
    $invoice = aid962PartlyCollectedInvoice();

    $result = aid962GenerateWith($view, $invoice);
    expect($result['success'])->toBeTrue();

    $text = aid962PdfText($result);
    $pos  = strpos($text, 'Collection');
    expect($pos)->not->toBeFalse();

    $tail   = substr($text, $pos);
    $credit = strpos($tail, '21.00');
    $paid   = strpos($tail, '40.00');
    $due    = strpos($tail, '60.00');

    expect($credit)->not->toBeFalse()
        ->and($paid)->toBeGreaterThan($credit)
        ->and($due)->toBeGreaterThan($paid)
        ->and($tail)->toContain('Credit applied')
        ->and($tail)->toContain('Amount paid')
        ->and($tail)->toContain('Amount due');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
    'exempt'         => 'larabill::pdf.invoice.exempt',
]);

it('renders no collection block for an unpaid invoice', function (string $view) {
    $invoice = aid962IssuedInvoice(12100, 2100);

    $text = aid962PdfText(aid962GenerateWith($view, $invoice));

    expect($text)->not->toContain('Collection')
        ->and($text)->not->toContain('Amount due');
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
]);

it('renders the block in Spanish under the es locale', function () {
    app()->setLocale('es');
    $invoice = aid962PartlyCollectedInvoice();

    $text = aid962PdfText(aid962GenerateWith('larabill::pdf.invoice.fiscal', $invoice));

    expect($text)->toContain('Saldo aplicado')->and($text)->toContain('Pendiente');
});

it('never feeds the proforma, even with raw pivot rows', function () {
    CompanyFiscalConfig::factory()->create(['currency' => 'EUR']);

    $proforma = Invoice::factory()->proforma()->create([
        'serie'            => InvoiceSerieType::PROFORMA->value,
        'status'           => InvoiceStatus::SENT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_2,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
    ]);
    $proforma->snapshotFiscalConfigs();
    $proforma->save();
    $payment = app(PaymentService::class)->receive(
        TestCase::USER_UUID_2, cents(500), 'EUR', now()->subHour(), PaymentMethod::BankTransfer, null, null, [], CreditMovementKind::TOP_UP,
    );
    DB::table('grouped_payment_invoice')->insert([
        'grouped_payment_id' => $payment->id,
        'invoice_id'         => $proforma->id,
        'applied_amount'     => 500,
        'status'             => 0,
        'previous_status'    => 0,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $text = aid962PdfText(aid962GenerateWith('larabill::pdf.invoice.proforma', $proforma));

    expect($text)->not->toContain('Collection');
});
