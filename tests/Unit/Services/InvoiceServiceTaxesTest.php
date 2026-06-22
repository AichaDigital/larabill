<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression for AID-138: InvoiceService::createInvoice produced invoices
 * without VAT because calculateForInvoiceItem never resolved a tax group,
 * and the item's immutable taxes_applied snapshot was never persisted.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = User::factory()->create();

    $vatGroup = TaxGroup::factory()->create(['name' => 'IVA General']);
    $rate     = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $vatGroup->taxRates()->attach($rate->id);

    $this->article = Article::factory()->create(['tax_group_id' => $vatGroup->id]);
});

it('creates invoices with VAT and the taxes_applied snapshot persisted', function () {
    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [
            [
                'article_id'  => $this->article->id,
                'quantity'    => 100,    // 1.0 unit in base100
                'base_price'  => 10000, // €100.00 in base100
                'description' => 'Hosting anual',
            ],
        ],
    ]);

    $item = $invoice->items->first();

    expect($item->taxable_amount->unscaledValue())->toBe(10000)
        ->and($item->total_tax_amount->unscaledValue())->toBe(2100)
        ->and($item->total_amount->unscaledValue())->toBe(12100)
        ->and($item->taxes_applied)->toHaveCount(1)
        ->and($item->taxes_applied[0]['rate'])->toBe(2100);

    expect($invoice->taxable_amount->unscaledValue())->toBe(10000)
        ->and($invoice->total_tax_amount->unscaledValue())->toBe(2100)
        ->and($invoice->total_amount->unscaledValue())->toBe(12100);
});

it('creates tax-free items for articles without a tax group', function () {
    $untaxed = Article::factory()->create(['tax_group_id' => null]);

    $invoice = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [
            [
                'article_id'  => $untaxed->id,
                'quantity'    => 100,
                'base_price'  => 5000,
                'description' => 'Servicio exento',
            ],
        ],
    ]);

    $item = $invoice->items->first();

    expect($item->total_tax_amount->unscaledValue())->toBe(0)
        ->and($item->taxes_applied)->toBe([])
        ->and($invoice->total_amount->unscaledValue())->toBe(5000);
});
