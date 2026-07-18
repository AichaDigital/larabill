<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
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
