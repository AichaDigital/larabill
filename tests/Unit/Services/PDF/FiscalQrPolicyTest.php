<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\MissingFiscalVerificationQrException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

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
    return Invoice::factory()->create([
        'fiscal_number'          => 'QR-AXES',
        'serie'                  => $serie->value,
        'status'                 => $status->value,
        'user_id'                => TestCase::USER_UUID_1,
        'fiscal_verification_id' => $registered ? 'REG-1' : null,
        'fiscal_verified_at'     => $registered ? now() : null,
    ]);
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
