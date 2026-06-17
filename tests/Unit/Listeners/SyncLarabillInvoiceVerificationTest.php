<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegimeTypeEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\InvoiceRegisteredEvent;
use AichaDigital\LaraVerifactu\Models\Invoice as VerifactuInvoice;
use AichaDigital\LaraVerifactu\Models\Registry as VerifactuRegistry;
use Illuminate\Support\Facades\Event;

it('updates the larabill invoice when lara-verifactu registers it asynchronously', function () {
    $invoice = Invoice::factory()->create([
        'user_id'                => TestCase::USER_UUID_1,
        'fiscal_verification_id' => null,
        'fiscal_verification_qr' => null,
        'fiscal_verified_at'     => null,
    ]);

    $verifactuInvoice = VerifactuInvoice::create([
        'serie'             => 'A',
        'number'            => '1',
        'issue_datetime'    => now(),
        'type'              => InvoiceTypeEnum::COMPLETE,
        'simplified'        => false,
        'base_amount'       => 100.00,
        'tax_amount'        => 21.00,
        'total_amount'      => 121.00,
        'currency'          => 'EUR',
        'regime_type'       => RegimeTypeEnum::GENERAL,
        'operation_key'     => OperationTypeEnum::NORMAL,
        'metadata'          => [
            'larabill_invoice_id' => $invoice->id,
        ],
    ]);

    $registry = VerifactuRegistry::create([
        'invoice_id'           => $verifactuInvoice->id,
        'registry_number'      => 'REG-000001',
        'registry_date'        => now(),
        'hash'                 => str_repeat('A', 64),
        'previous_hash'        => null,
        'hash_generated_at'    => now()->format('c'),
        'qr_url'               => 'https://prewww2.aeat.es/qr?id=REG-000001',
        'qr_svg'               => '<svg data-testid="verifactu-qr"></svg>',
        'qr_png'               => null,
        'xml'                  => '<xml></xml>',
        'signed_xml'           => null,
        'status'               => RegistryStatusEnum::PENDING,
        'submission_attempts'  => 0,
    ]);

    Event::dispatch(new InvoiceRegisteredEvent($verifactuInvoice, $registry, false));

    $invoice->refresh();

    expect($invoice->fiscal_verification_id)->toBe('REG-000001')
        ->and($invoice->fiscal_verification_qr)->toBe('<svg data-testid="verifactu-qr"></svg>')
        ->and($invoice->fiscal_verification_hash)->toBe(str_repeat('A', 64))
        ->and($invoice->fiscal_verified_at)->not->toBeNull()
        ->and($invoice->fiscal_verification_metadata)->toMatchArray([
            'provider'          => 'lara-verifactu',
            'verifactu_invoice' => $verifactuInvoice->id,
            'qr_url'            => 'https://prewww2.aeat.es/qr?id=REG-000001',
            'submitted_to_aeat' => false,
        ]);
});
