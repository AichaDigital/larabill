<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\Larabill\Tests\Models\User;
use AichaDigital\LaraVerifactu\Enums\InvoiceTypeEnum;
use AichaDigital\LaraVerifactu\Models\Invoice as VerifactuInvoice;
use AichaDigital\LaraVerifactu\Models\InvoiceBreakdown as VerifactuBreakdown;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Create an invoice with deterministic base-100 amounts and optional items.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>  $items
 * @param  array<string, mixed>|null  $customerData  Encrypted customer snapshot payload
 * @param  array<string, mixed>|null  $issuerData  Encrypted issuer snapshot payload (country_code, is_oss)
 */
function makeVerifactuSourceInvoice(array $attributes = [], array $items = [], ?array $customerData = null, ?array $issuerData = null): Invoice
{
    if ($customerData !== null) {
        $attributes['customer_snapshot'] = Crypt::encryptString(json_encode($customerData));
    }

    if ($issuerData !== null) {
        $attributes['issuer_snapshot'] = Crypt::encryptString(json_encode($issuerData));
    }

    $invoiceAttrs = fdMoney(array_merge([
        'user_id'          => User::factory()->create()->id,
        'taxable_amount'   => 10000, // €100.00
        'total_tax_amount' => 2100,  // €21.00
        'total_amount'     => 12100, // €121.00
        'is_roi_taxed'     => false,
        'issued_at'        => '2026-06-01 10:30:00',
        'invoice_date'     => '2026-06-01',
    ], $attributes), ['taxable_amount', 'total_tax_amount', 'total_amount']);

    $invoice = Invoice::factory()->create($invoiceAttrs);

    foreach ($items as $itemAttributes) {
        InvoiceItem::factory()->create(fdMoney(array_merge([
            'invoice_id' => $invoice->id,
        ], $itemAttributes), ['quantity', 'unit_price', 'taxable_amount', 'total_tax_amount', 'total_amount']));
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

        // 'simplified' was dropped from the verifactu model (1.0); the F1/F2 type carries it.
        expect($data['type'])->toBe('F1');
    });

    it('maps invoices without recipient to F2 regardless of amount', function () {
        $large = makeVerifactuSourceInvoice(['total_amount' => 500000]); // €5000.00, no snapshot

        $data = VerifactuAdapter::toVerifactuInvoice($large);

        expect($data['type'])->toBe('F2');
    });

    it('maps rectificative invoices to R1 with incremental rectification type', function () {
        $original      = makeVerifactuSourceInvoice();
        $rectificative = makeVerifactuSourceInvoice(['rectifies_invoice_id' => $original->id]);

        $data = VerifactuAdapter::toVerifactuInvoice($rectificative);

        // AEAT ClaveTipoRectificativaType is S|I; larabill rectifications
        // are por diferencias → 'I' (lara-verifactu 0.10 emits it verbatim).
        expect($data['type'])->toBe('R1')
            ->and($data['rectification_type'])->toBe('I');
    });

    it('references the rectified invoice in metadata for FacturasRectificadas', function () {
        $original = makeVerifactuSourceInvoice([
            'issued_at'    => '2026-06-01 10:30:00',
            'invoice_date' => '2026-06-01',
        ]);
        $rectificative = makeVerifactuSourceInvoice(['rectifies_invoice_id' => $original->id]);

        $data = VerifactuAdapter::toVerifactuInvoice($rectificative);

        $expectedNumber = $original->serie->value.$original->series_number;

        expect($data['metadata']['rectified_invoices'])->toHaveCount(1)
            ->and($data['metadata']['rectified_invoices'][0]['number'])->toBe((string) $expectedNumber)
            ->and($data['metadata']['rectified_invoices'][0]['issue_date'])->toBe('2026-06-01');
    });

    it('does not add rectified_invoices metadata to non-rectificative invoices', function () {
        $invoice = makeVerifactuSourceInvoice();

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data['metadata'])->not->toHaveKey('rectified_invoices');
    });

    it('produces a payload accepted by the native verifactu invoice model casts', function () {
        $invoice = makeVerifactuSourceInvoice();

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        $verifactuInvoice = new VerifactuInvoice($data);

        expect($verifactuInvoice->issue_datetime)->not->toBeNull()
            ->and($verifactuInvoice->type)->toBeInstanceOf(InvoiceTypeEnum::class);
    });

    it('no longer emits the dropped operation_key field (lara-verifactu 1.0)', function () {
        $invoice = makeVerifactuSourceInvoice(['is_roi_taxed' => true]);

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data)->not->toHaveKey('operation_key');
    });

    it('emits serie as a string so it satisfies the verifactu getSerie() ?string contract', function () {
        // InvoiceSerieType is an int-backed enum; emitting ->value raw produced an
        // int serie that broke VerifactuInvoice::getSerie() (typed ?string) during
        // XML build. The adapter must cast it to a string.
        $invoice = makeVerifactuSourceInvoice();

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data['serie'])->toBeString();
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

describe('VerifactuAdapter::toVerifactuInvoice (intra-EU recipient identification — AID-136)', function () {
    it('emits a VAT-registered EU B2B recipient as IDOtro NIF-IVA (02) with a null recipient_nif', function () {
        $invoice = makeVerifactuSourceInvoice([], [], [
            'tax_id'               => 'DE129273398',
            'fiscal_name'          => 'Muster GmbH',
            'country_code'         => 'DE',
            'is_company'           => true,
            'is_eu_vat_registered' => true,
        ], ['country_code' => 'ES', 'is_oss' => false]);

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        // AEAT rule 1100: a foreign VAT must NOT be emitted as a Spanish <NIF>.
        expect($data['recipient_nif'])->toBeNull()
            ->and($data['recipient_id'])->toBe('DE129273398')
            ->and($data['recipient_id_type'])->toBe('02')
            ->and($data['recipient_country'])->toBe('DE');
    });

    it('emits an EU B2C consumer without VAT as IDOtro official document (04)', function () {
        $invoice = makeVerifactuSourceInvoice([], [], [
            'tax_id'               => 'X1234567Z',
            'fiscal_name'          => 'Jean Client',
            'country_code'         => 'FR',
            'is_company'           => false,
            'is_eu_vat_registered' => false,
        ], ['country_code' => 'ES', 'is_oss' => false]);

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data['recipient_nif'])->toBeNull()
            ->and($data['recipient_id'])->toBe('X1234567Z')
            ->and($data['recipient_id_type'])->toBe('04')
            ->and($data['recipient_country'])->toBe('FR');
    });

    it('keeps a Spanish recipient as a domestic NIF (02) without regression', function () {
        $invoice = makeVerifactuSourceInvoice([], [], [
            'tax_id'               => 'B75685883',
            'fiscal_name'          => 'ACME SL',
            'country_code'         => 'ES',
            'is_company'           => true,
            'is_eu_vat_registered' => true,
        ]);

        $data = VerifactuAdapter::toVerifactuInvoice($invoice);

        expect($data['recipient_nif'])->toBe('B75685883')
            ->and($data['recipient_id_type'])->toBe('02')
            ->and($data['recipient_country'])->toBe('ES');
    });
});

describe('VerifactuAdapter::toVerifactuBreakdowns (intra-EU N2 + guards — AID-136)', function () {
    $b2bDe = [
        'tax_id'               => 'DE129273398',
        'fiscal_name'          => 'Muster GmbH',
        'country_code'         => 'DE',
        'is_company'           => true,
        'is_eu_vat_registered' => true,
    ];
    $esIssuer = ['country_code' => 'ES', 'is_oss' => false];

    it('produces a single N2 breakdown for an EU B2B service invoice with a VAT-registered customer', function () use ($b2bDe, $esIssuer) {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000],
            [['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000, 'item_type' => ItemType::SERVICE, 'taxes_applied' => []]],
            $b2bDe,
            $esIssuer,
        );

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect($breakdowns)->toHaveCount(1)
            ->and($breakdowns[0]['calificacion'])->toBe('N2')
            ->and($breakdowns[0]['tax_rate'])->toBe(0.0)
            ->and($breakdowns[0]['tax_amount'])->toBe(0.0)
            ->and($breakdowns[0]['base_amount'])->toBe(100.0)
            ->and($breakdowns[0]['exempt'])->toBeFalse();
    });

    it('still produces N2 when only zero-amount tax snapshots are present (not real VAT)', function () use ($b2bDe, $esIssuer) {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000],
            [['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000, 'item_type' => ItemType::SERVICE, 'taxes_applied' => [['rate' => 0, 'amount' => 0]]]],
            $b2bDe,
            $esIssuer,
        );

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect($breakdowns)->toHaveCount(1)
            ->and($breakdowns[0]['calificacion'])->toBe('N2');
    });

    it('flags N2 for a non-Spanish issuer selling services to a VAT-registered customer in another EU country', function () {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 30000, 'total_tax_amount' => 0, 'total_amount' => 30000],
            [['taxable_amount' => 30000, 'total_tax_amount' => 0, 'total_amount' => 30000, 'item_type' => ItemType::SERVICE, 'taxes_applied' => []]],
            ['tax_id'       => 'B75685883', 'country_code' => 'ES', 'is_company' => true, 'is_eu_vat_registered' => true],
            ['country_code' => 'FR', 'is_oss' => false],
        );

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect($breakdowns)->toHaveCount(1)
            ->and($breakdowns[0]['calificacion'])->toBe('N2');
    });

    it('does NOT flag N2 when issuer and customer share the same EU country, even with a live ES config (snapshot-immutable, non-Spain-centric)', function () {
        // If the adapter read CompanyFiscalConfig::getActive() (ES) instead of the FR
        // issuer snapshot, FR customer ≠ ES issuer would wrongly trigger N2.
        CompanyFiscalConfig::factory()->create(['country_code' => 'ES', 'is_active' => true, 'valid_until' => null]);

        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 20000, 'total_tax_amount' => 4200, 'total_amount' => 24200],
            [['taxable_amount' => 20000, 'total_tax_amount' => 4200, 'total_amount' => 24200, 'item_type' => ItemType::SERVICE, 'taxes_applied' => [['rate' => 2100, 'amount' => 4200]]]],
            ['tax_id'       => 'FR12345678901', 'country_code' => 'FR', 'is_company' => true, 'is_eu_vat_registered' => true],
            ['country_code' => 'FR', 'is_oss' => false],
        );

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect(collect($breakdowns)->pluck('calificacion')->filter()->all())->toBe([]);
    });

    it('rejects intra-EU goods fail-loud (E5 art.25 entrega de bienes, out of scope) instead of emitting N2', function () use ($b2bDe, $esIssuer) {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000],
            [['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000, 'item_type' => ItemType::GOOD, 'taxes_applied' => []]],
            $b2bDe,
            $esIssuer,
        );

        expect(fn () => VerifactuAdapter::toVerifactuBreakdowns($invoice))
            ->toThrow(ValidationException::class);
    });

    it('rejects an N2 candidate that still carries real VAT fail-loud (rule 1237 anti-deletion)', function () use ($b2bDe, $esIssuer) {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 2100, 'total_amount' => 12100],
            [['taxable_amount' => 10000, 'total_tax_amount' => 2100, 'total_amount' => 12100, 'item_type' => ItemType::SERVICE, 'taxes_applied' => [['rate' => 2100, 'amount' => 2100]]]],
            $b2bDe,
            $esIssuer,
        );

        expect(fn () => VerifactuAdapter::toVerifactuBreakdowns($invoice))
            ->toThrow(ValidationException::class);
    });

    it('rejects a B2C OSS sale fail-loud (régimen 17 out of scope) instead of silently emitting S1', function () {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 1900, 'total_amount' => 11900],
            [['taxable_amount' => 10000, 'total_tax_amount' => 1900, 'total_amount' => 11900, 'item_type' => ItemType::SERVICE, 'taxes_applied' => [['rate' => 1900, 'amount' => 1900]]]],
            ['tax_id'       => 'FR-CONSUMER-1', 'country_code' => 'FR', 'is_company' => false, 'is_eu_vat_registered' => false],
            ['country_code' => 'ES', 'is_oss' => true],
        );

        expect(fn () => VerifactuAdapter::toVerifactuBreakdowns($invoice))
            ->toThrow(ValidationException::class);
    });

    it('rejects an incomplete reverse-charge invoice fail-loud (is_roi_taxed without a complete intra-EU recipient)', function () {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000, 'is_roi_taxed' => true],
            [['taxable_amount' => 10000, 'total_tax_amount' => 0, 'total_amount' => 10000, 'item_type' => ItemType::SERVICE, 'taxes_applied' => []]],
        );

        expect(fn () => VerifactuAdapter::toVerifactuBreakdowns($invoice))
            ->toThrow(ValidationException::class);
    });

    it('keeps a domestic Spanish service invoice as S1 (no calificacion) without regression', function () {
        $invoice = makeVerifactuSourceInvoice(
            ['taxable_amount' => 10000, 'total_tax_amount' => 2100, 'total_amount' => 12100],
            [['taxable_amount' => 10000, 'total_tax_amount' => 2100, 'total_amount' => 12100, 'item_type' => ItemType::SERVICE, 'taxes_applied' => [['rate' => 2100, 'amount' => 2100]]]],
            ['tax_id'       => 'B75685883', 'country_code' => 'ES', 'is_company' => true, 'is_eu_vat_registered' => true],
            ['country_code' => 'ES', 'is_oss' => false],
        );

        $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

        expect($breakdowns)->toHaveCount(1)
            ->and($breakdowns[0]['tax_rate'])->toBe(21.0)
            ->and(collect($breakdowns)->pluck('calificacion')->filter()->all())->toBe([]);
    });
});
