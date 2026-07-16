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
