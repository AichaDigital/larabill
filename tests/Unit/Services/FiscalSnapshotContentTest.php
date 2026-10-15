<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Support\RegionalContext;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/**
 * AID-534 — the frozen fiscal snapshot carries the tax rules actually applied.
 *
 * tax_rules_applied was designed into the snapshot and persisted always []
 * behind a confessional TODO: the frozen evidence claimed a datum it never
 * carried. It now aggregates the items' immutable taxes_applied entries —
 * same frozen lines, same instant, so coherence holds by construction.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function aid534FiscalSnapshot(Invoice $invoice): array
{
    return json_decode(Crypt::decryptString((string) $invoice->fiscal_snapshot), true);
}

it('freezes the aggregated tax rules of the lines into the fiscal snapshot', function () {
    $service = app(InvoiceService::class);

    // Frozen-line payloads (the AID-556/557 mechanism) make the per-line
    // taxes_applied deterministic without live tax-catalog fixtures.
    $invoice = $service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [
            [
                'description'      => 'Line A',
                'quantity'         => 100,
                'base_price'       => 10000,
                'taxable_amount'   => 10000,
                'total_tax_amount' => 2100,
                'taxes_applied'    => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]],
                'total_amount'     => 12100,
            ],
            [
                'description'      => 'Line B',
                'quantity'         => 100,
                'base_price'       => 5000,
                'taxable_amount'   => 5000,
                'total_tax_amount' => 1050,
                'taxes_applied'    => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 1050]],
                'total_amount'     => 6050,
            ],
            [
                'description'      => 'Line C',
                'quantity'         => 100,
                'base_price'       => 3000,
                'taxable_amount'   => 3000,
                'total_tax_amount' => 300,
                'taxes_applied'    => [['source_rate_id' => 2, 'name' => 'IVA 10%', 'rate' => 1000, 'amount' => 300]],
                'total_amount'     => 3300,
            ],
        ],
    ]);

    // One entry per distinct rule, base and amount summed, rate descending
    // (the tax-breakdown ordering precedent, AID-508).
    expect(aid534FiscalSnapshot($invoice)['tax_rules_applied'])->toBe([
        ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'base' => 15000, 'amount' => 3150],
        ['source_rate_id' => 2, 'name' => 'IVA 10%', 'rate' => 1000, 'base' => 3000, 'amount' => 300],
    ]);
});

it('freezes an empty rule set for an invoice without taxed lines', function () {
    $service = app(InvoiceService::class);

    $invoice = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);

    expect(aid534FiscalSnapshot($invoice)['tax_rules_applied'])->toBe([]);
});

/**
 * AID-929 (D9) — the reverse-charge qualification belongs in the frozen
 * fiscal context.
 *
 * There are two generators of this snapshot and their schemas had drifted:
 * Invoice::generateFiscalContextSnapshot() carried `is_roi_applied`, while
 * InvoiceService::generateFiscalSnapshot() — the one the canonical issuing
 * path uses — did not. So the document that ADR-001 says freezes its own
 * state was omitting the datum that decides its PDF template, its OSS
 * threshold treatment and its Verifactu qualification. Same key name as the
 * other generator: a third vocabulary for one concept is how they drift again.
 */
it('freezes the reverse-charge qualification into the canonical fiscal snapshot', function () {
    $service = app(InvoiceService::class);

    $invoice = $service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [],
    ]);

    expect(aid534FiscalSnapshot($invoice)['is_roi_applied'])->toBeTrue();
});

it('freezes a false reverse-charge qualification rather than omitting the key', function () {
    $service = app(InvoiceService::class);

    $invoice = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);

    expect(aid534FiscalSnapshot($invoice))->toHaveKey('is_roi_applied')
        ->and(aid534FiscalSnapshot($invoice)['is_roi_applied'])->toBeFalse();
});

it('regenerates the fiscal snapshot at issuance from the same instant as the correlative (AID-1649)', function () {
    $service = app(InvoiceService::class);

    // §7.2: the explicit draft births with the PROVISIONAL snapshot — null
    // number, the draft's fiscal year and date baked in. The issued row must
    // NOT keep it: issue() regenerates from the issuance instant (the same
    // generator the birth used, AID-328).
    $draft    = $service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
        'status'           => 'draft',
    ]);
    $birthSnapshot = aid534FiscalSnapshot($draft);
    expect($birthSnapshot['fiscal_number'])->toBeNull()
        ->and($birthSnapshot['series_number'])->toBeNull();

    $issued = $service->issue($draft);
    $data   = aid534FiscalSnapshot($issued);

    expect($data['fiscal_number'])->toBe($issued->fiscal_number)
        ->and($data['series_number'])->toBe($issued->series_number)
        ->and($data['fiscal_year'])->toBe($issued->fiscal_year)
        ->and($data['fiscal_year'])->toBe(RegionalContext::getFiscalYear($issued->issued_at))
        ->and(Carbon::parse($data['invoice_date'])->toDateString())->toBe($issued->invoice_date->toDateString())
        ->and($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED);
});

it('regenerates the snapshot on the conversion path too: the PREPARED birth snapshot dies at issuance (AID-1649)', function () {
    $service = app(InvoiceService::class);

    $proforma  = $service->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]);
    $prepared = $service->convertProformaToInvoice($proforma)->invoice;
    expect(aid534FiscalSnapshot($prepared)['fiscal_number'])->toBeNull();

    $issued = $service->issue($prepared);
    $data   = aid534FiscalSnapshot($issued);

    expect($data['fiscal_number'])->toBe($issued->fiscal_number)
        ->and($data['fiscal_year'])->toBe($issued->fiscal_year);
});

it('keeps ONE vocabulary: a hook-born draft issued by issue() carries the unified key set (AID-1649, gate by clientes)', function () {
    $service = app(InvoiceService::class);

    // The clientes production path: the draft is born through ELOQUENT (the
    // creating hook and its generator), then issued. Every context key the
    // hook's generator carried must survive the issuance regeneration.
    $draft = new Invoice([
        'fiscal_number'            => null,
        'prefix'                   => 'FAC',
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'series_number'            => null,
        'fiscal_year'              => null,
        'invoice_date'             => null,
        'issued_at'                => null,
        'invoice_document_status'  => InvoiceDocumentStatus::DRAFT,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->customer->id,
        'billable_user_id'         => $this->customer->id,
        'is_roi_taxed'             => true,
    ]);
    $draft->save();

    $issued = $service->issue($draft);
    $data   = aid534FiscalSnapshot($issued);

    expect($data)->toHaveKeys([
        'is_intra_community', 'issuer_is_oss', 'customer_is_vat_reg',
        'company_fiscal_config_id', 'user_tax_profile_id', 'currency',
        'tax_rules_applied', 'is_roi_applied', 'fiscal_year', 'fiscal_number',
        'series_number', 'invoice_date', 'issuer_country', 'customer_country',
    ])
        ->and($data['is_roi_applied'])->toBeTrue()
        ->and($data['company_fiscal_config_id'])->toBe($issued->company_fiscal_config_id)
        ->and($data['fiscal_number'])->toBe($issued->fiscal_number);
});

it('issues the SAME snapshot key set whichever way the document was born (AID-328 closure)', function () {
    $service = app(InvoiceService::class);

    $keys = fn (Invoice $issued): array => array_keys(aid534FiscalSnapshot($issued));

    // Service-born (createInvoice + issue).
    $serviceBorn = $service->issue($service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]));

    // Conversion-born (PREPARED + issue).
    $proforma = $service->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'Item', 'quantity' => 100, 'base_price' => 1000]],
    ]);
    $conversionBorn = $service->issue($service->convertProformaToInvoice($proforma)->invoice);

    // Hook-born (Eloquent draft + issue).
    $hookBorn = new Invoice([
        'prefix'                   => 'FAC',
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'invoice_document_status'  => InvoiceDocumentStatus::DRAFT,
        'status'                   => InvoiceStatus::DRAFT,
        'user_id'                  => $this->customer->id,
        'billable_user_id'         => $this->customer->id,
    ]);
    $hookBorn->save();
    $hookIssued = $service->issue($hookBorn);

    expect($keys($serviceBorn))->toBe($keys($conversionBorn))
        ->and($keys($serviceBorn))->toBe($keys($hookIssued));
});
