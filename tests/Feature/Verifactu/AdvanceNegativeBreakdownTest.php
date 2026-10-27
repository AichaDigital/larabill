<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-1778 spec §8.3 R23 (G13) — the frozen NEGATIVE deduction line reaches
 * the Verifactu per-rate breakdown VERBATIM: `VerifactuAdapter::
 * toVerifactuBreakdowns()` groups by rate and sums `taxes_applied[].amount`,
 * so nothing may zero or flip the negative tax on the way to the payload.
 *
 * SCOPE LIMIT (H9): this is a LOCAL payload test only. It proves nothing
 * about AEAT acceptance of a negative per-rate breakdown — that is S5's
 * separate hard gate (T6, AEAT sandbox), and no local pass here downgrades it.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->customer = TestUser::factory()->create();

    $this->group = TaxGroup::factory()->create(['name' => 'IVA General 21%']);
    $this->rate  = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $this->group->taxRates()->attach($this->rate->id);
});

it('carries the frozen negative deduction tax into the Verifactu breakdown verbatim (R23)', function () {
    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => (string) $this->customer->id,
        'type'             => 'invoice',
        'series'           => 'FAC',
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            [
                // The frozen deduction line of the AID-1778 helper: the
                // advance's own rate, negative amounts, nothing recomputed.
                'description'        => 'Deduction of advance invoice FAC-1',
                'quantity'           => 100,
                'base_price'         => -8264,
                'taxable_amount'     => -8264,
                'total_tax_amount'   => -1736,
                'taxes_applied'      => [[
                    'source_rate_id' => $this->rate->id,
                    'name'           => $this->rate->name,
                    'rate'           => 2100,
                    'amount'         => -1736,
                ]],
                'total_amount'       => -10000,
            ],
        ],
    ], ['make_immutable' => true]);

    $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);

    expect($breakdowns)->toHaveCount(1)
        ->and($breakdowns[0]['tax_rate'])->toBe(21.0)
        ->and($breakdowns[0]['base_amount'])->toBe(-8264 / 100)
        ->and($breakdowns[0]['tax_amount'])->toBe(-1736 / 100)
        ->and($breakdowns[0]['exempt'])->toBeFalse();
});
