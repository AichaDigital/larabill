<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\Larabill\Tests\Models\User;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Enums\OperationTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice as VerifactuInvoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown as VerifactuBreakdown;
use Illuminate\Support\Facades\Crypt;

/**
 * Create an invoice with deterministic base-100 amounts and optional items.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<string, mixed>|null  $customerData  Encrypted customer snapshot payload
 */
function makeVerifactuSourceInvoice(array $attributes = [], array $items = [], ?array $customerData = null): Invoice
{
    if ($customerData !== null) {
        $attributes['customer_snapshot'] = Crypt::encryptString(json_encode($customerData));
    }

    $invoice = Invoice::factory()->create(array_merge([
        'user_id'          => User::factory()->create()->id,
        'taxable_amount'   => 10000, // €100.00
        'total_tax_amount' => 2100,  // €21.00
        'total_amount'     => 12100, // €121.00
        'is_roi_taxed'     => false,
        'issued_at'        => '2026-06-01 10:30:00',
        'invoice_date'     => '2026-06-01',
    ], $attributes));

    foreach ($items as $itemAttributes) {
        InvoiceItem::factory()->create(array_merge([
            'invoice_id' => $invoice->id,
        ], $itemAttributes));
    }

    return $invoice->fresh(['items']);
}

describe('VerifactuAdapter::toVerifactuInvoice', function () {
    it('emits issue_datetime instead of the legacy issue_date and issue_time keys', function () {
        $invoice = makeVerifactuSourceInvoice();

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data)->toHaveKey('issue_datetime')
            ->and($data)->not->toHaveKey('issue_date')
            ->and($data)->not->toHaveKey('issue_time');
    });

    it('only emits keys that are fillable on the native verifactu invoice model', function () {
        $invoice = makeVerifactuSourceInvoice();

        $data     = VerifactuAdapter::toVerifactuInvoice($invoice);
        $fillable = (new VerifactuInvoice)->getFillable();

        expect(array_diff(array_keys($data), $fillable))->toBe([]);
    });

    it('converts base-100 amounts to decimals', function () {
        $invoice = makeVerifactuSourceInvoice([
            'taxable_amount'   => 12345, // €123.45
            'total_tax_amount' => 2593,  // €25.93
            'total_amount'     => 14938, // €149.38
        ]);

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data['base_amount'])->toBe(123.45)
            ->and($data['tax_amount'])->toBe(25.93)
            ->and($data['total_amount'])->toBe(149.38);
    });

    it('maps invoices with an identified recipient to F1 regardless of amount', function () {
        // AEAT rule 1190: F2 must not carry a Destinatarios block, so any
        // invoice with an identified recipient is F1 — even a €12 one.
        $small = makeVerifactuSourceInvoice(
            ['total_amount' => 1210], // €12.10
            [],
            ['tax_id' => 'B75685883', 'fiscal_name' => 'ACME SL', 'country_code' => 'ES'],
        );

        $data = VerifactuAdapter::toVerifactuInvoice($small);

        expect($data['type'])->toBe('F1')
            ->and($data['simplified'])->toBeFalse();
    });

    it('maps invoices without recipient to F2 regardless of amount', function () {
        $large = makeVerifactuSourceInvoice(['total_amount' => 500000]); // €5000.00, no snapshot

        $data = VerifactuAdapter::toVerifactuInvoice($large);

        expect($data['type'])->toBe('F2')
            ->and($data['simplified'])->toBeTrue();
    });

    it('maps rectificative invoices to R1', function () {
        $original      = makeVerifactuSourceInvoice();
        $rectificative = makeVerifactuSourceInvoice(['rectifies_invoice_id' => $original->id]);

        $data = VerifactuAdapter::toVerifactuInvoice($rectificative);

        expect($data['type'])->toBe('R1')
            ->and($data['rectification_type'])->toBe('R1');
    });

    it('maps ROI-taxed invoices to the reverse charge operation key supported by the 0.9 enum', function () {
        $invoice = makeVerifactuSourceInvoice(['is_roi_taxed' => true]);

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        // '09' does not exist in OperationTypeEnum 0.9; reverse charge is '04'.
        expect($data['operation_key'])->toBe('04');
    });

    it('produces a payload accepted by the native verifactu invoice model casts', function () {
        $invoice = makeVerifactuSourceInvoice();

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        $verifactuInvoice = new VerifactuInvoice($data);

        expect($verifactuInvoice->issue_datetime)->not->toBeNull()
            ->and($verifactuInvoice->type)->toBeInstanceOf(InvoiceTypeEnum::class)
            ->and($verifactuInvoice->operation_key)->toBeInstanceOf(OperationTypeEnum::class);
    });
});

describe('VerifactuAdapter::toVerifactuBreakdowns', function () {
    it('groups items by tax rate summing bases and tax amounts', function () {
        $invoice = makeVerifactuSourceInvoice([], [
            [
                'taxable_amount'   => 10000, // €100.00 at 21%
                'total_tax_amount' => 2100,
                'total_amount'     => 12100,
                'taxes_applied'    => [
                    ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100],
                ],
            ],
            [
                'taxable_amount'   => 5000, // €50.00 at 21%
                'total_tax_amount' => 1050,
                'total_amount'     => 6050,
                'taxes_applied'    => [
                    ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 1050],
                ],
            ],
            [
                'taxable_amount'   => 2000, // €20.00 at 10%
                'total_tax_amount' => 200,
                'total_amount'     => 2200,
                'taxes_applied'    => [
                    ['source_rate_id' => 2, 'name' => 'IVA 10%', 'rate' => 1000, 'amount' => 200],
                ],
            ],
        ]);

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect($breakdowns)->toHaveCount(2);

        $standard = collect($breakdowns)->firstWhere('tax_rate', 21.0);
        $reduced  = collect($breakdowns)->firstWhere('tax_rate', 10.0);

        expect($standard['base_amount'])->toBe(150.0)
            ->and($standard['tax_amount'])->toBe(31.5)
            ->and($standard['exempt'])->toBeFalse()
            ->and($reduced['base_amount'])->toBe(20.0)
            ->and($reduced['tax_amount'])->toBe(2.0);
    });

    it('emits a single exempt row aggregating items without taxes', function () {
        $invoice = makeVerifactuSourceInvoice([], [
            [
                'taxable_amount'   => 3000,
                'total_tax_amount' => 0,
                'total_amount'     => 3000,
                'taxes_applied'    => [],
            ],
            [
                'taxable_amount'   => 1500,
                'total_tax_amount' => 0,
                'total_amount'     => 1500,
                'taxes_applied'    => [],
            ],
        ]);

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect($breakdowns)->toHaveCount(1)
            ->and($breakdowns[0]['exempt'])->toBeTrue()
            ->and($breakdowns[0]['base_amount'])->toBe(45.0)
            ->and($breakdowns[0]['tax_amount'])->toBe(0.0);
    });

    it('only emits keys that are fillable on the native verifactu breakdown model', function () {
        $invoice = makeVerifactuSourceInvoice([], [
            [
                'taxable_amount'   => 10000,
                'total_tax_amount' => 2100,
                'total_amount'     => 12100,
                'taxes_applied'    => [
                    ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100],
                ],
            ],
        ]);

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);
        $fillable   = (new VerifactuBreakdown)->getFillable();

        foreach ($breakdowns as $breakdown) {
            expect(array_diff(array_keys($breakdown), $fillable))->toBe([]);
        }
    });
});
