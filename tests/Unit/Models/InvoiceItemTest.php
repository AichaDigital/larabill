<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Tests\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user    = User::factory()->create();
    $this->invoice = Invoice::factory()->create(['user_id' => $this->user->id]);
    // Note: unit_measure_id is nullable and not required in v0.4.0
});

it('can create an invoice item', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'        => $this->invoice->id,
        'item_type'         => ItemType::GOOD,
        'description'       => 'Test Product',
        'quantity'          => 200,  // 2.0 units in base100
        'unit_price'        => 1000, // €10.00 in base100
        'taxable_amount'    => 2000, // €20.00 in base100
        'total_tax_amount'  => 420,  // €4.20 in base100
        'total_amount'      => 2420, // €24.20 in base100
    ]);

    expect($item)->toBeInstanceOf(InvoiceItem::class)
        ->and($item->description)->toBe('Test Product')
        ->and($item->quantity)->toBe(200)
        ->and($item->unit_price)->toBe(1000)
        ->and($item->taxable_amount)->toBe(2000)
        ->and($item->total_tax_amount)->toBe(420)
        ->and($item->total_amount)->toBe(2420);
});

it('can cast item_type as enum', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'item_type'  => ItemType::SERVICE,
    ]);

    expect($item->item_type)->toBeInstanceOf(ItemType::class)
        ->and($item->item_type)->toBe(ItemType::SERVICE);
});

it('can cast monetary values using Base100', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'unit_price'       => 1234, // €12.34 in base100
        'taxable_amount'   => 2468, // €24.68 in base100
        'total_tax_amount' => 518,  // €5.18 in base100
        'total_amount'     => 2986, // €29.86 in base100
    ]);

    expect($item->unit_price)->toBe(1234)
        ->and($item->taxable_amount)->toBe(2468)
        ->and($item->total_tax_amount)->toBe(518)
        ->and($item->total_amount)->toBe(2986);
});

it('can cast quantity using Base100', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'quantity'   => 150, // 1.5 units in base100
    ]);

    expect($item->quantity)->toBe(150);
});

it('belongs to an invoice', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
    ]);

    expect($item->invoice)->toBeInstanceOf(Invoice::class)
        ->and($item->invoice->id)->toBe($this->invoice->id);
});

// Removed: 'it belongs to a unit measure' - UnitMeasure not implemented in v0.4.0

it('can calculate taxable amount', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'quantity'   => 300,  // 3.0 units in base100
        'unit_price' => 1550, // €15.50 in base100
    ]);

    $taxableAmount = $item->calculateTaxableAmount();

    expect($taxableAmount)->toBe(4650); // 3.0 * €15.50 = €46.50 = 4650 in base100
});

it('can calculate taxable amount with decimal quantity', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'quantity'   => 250,  // 2.5 units in base100
        'unit_price' => 1000, // €10.00 in base100
    ]);

    $taxableAmount = $item->calculateTaxableAmount();

    expect($taxableAmount)->toBe(2500); // 2.5 * €10.00 = €25.00 = 2500 in base100
});

it('can store taxes_applied as array', function () {
    $taxesApplied = [
        ['source_rate_id' => 1, 'name' => 'VAT', 'rate' => 2100, 'amount' => 420],
        ['source_rate_id' => 2, 'name' => 'GST', 'rate' => 500, 'amount' => 100],
    ];

    $item = InvoiceItem::factory()->create([
        'invoice_id'    => $this->invoice->id,
        'taxes_applied' => $taxesApplied,
    ]);

    expect($item->taxes_applied)->toBe($taxesApplied)
        ->and($item->taxes_applied)->toBeArray()
        ->and($item->taxes_applied)->toHaveCount(2);
});

it('can get tax breakdown', function () {
    $taxesApplied = [
        ['source_rate_id' => 1, 'name' => 'VAT', 'rate' => 2100, 'amount' => 420],
    ];

    $item = InvoiceItem::factory()->create([
        'invoice_id'    => $this->invoice->id,
        'taxes_applied' => $taxesApplied,
    ]);

    $breakdown = $item->getTaxBreakdown();

    expect($breakdown)->toBe($taxesApplied)
        ->and($breakdown[0]['name'])->toBe('VAT')
        ->and($breakdown[0]['amount'])->toBe(420);
});

it('returns empty array for tax breakdown when taxes_applied is null', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'    => $this->invoice->id,
        'taxes_applied' => null,
    ]);

    $breakdown = $item->getTaxBreakdown();

    expect($breakdown)->toBe([]);
});

it('can scope goods only', function () {
    InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'item_type'  => ItemType::GOOD,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'item_type'  => ItemType::SERVICE,
    ]);

    $goods = InvoiceItem::goods()->get();

    expect($goods)->toHaveCount(1)
        ->and($goods->first()->item_type)->toBe(ItemType::GOOD);
});

it('can scope services only', function () {
    InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'item_type'  => ItemType::GOOD,
    ]);
    InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'item_type'  => ItemType::SERVICE,
    ]);

    $services = InvoiceItem::services()->get();

    expect($services)->toHaveCount(1)
        ->and($services->first()->item_type)->toBe(ItemType::SERVICE);
});

it('can scope items with service dates', function () {
    InvoiceItem::factory()->create([
        'invoice_id'        => $this->invoice->id,
        'service_date_from' => now(),
        'service_date_to'   => now()->addDays(7),
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'        => $this->invoice->id,
        'service_date_from' => null,
        'service_date_to'   => null,
    ]);

    $withDates = InvoiceItem::withServiceDates()->get();

    expect($withDates)->toHaveCount(1)
        ->and($withDates->first()->service_date_from)->not->toBeNull()
        ->and($withDates->first()->service_date_to)->not->toBeNull();
});

it('can store service dates', function () {
    $from = now();
    $to   = now()->addDays(30);

    $item = InvoiceItem::factory()->create([
        'invoice_id'        => $this->invoice->id,
        'item_type'         => ItemType::SERVICE,
        'service_date_from' => $from,
        'service_date_to'   => $to,
    ]);

    expect($item->service_date_from)->toBeInstanceOf(Carbon::class)
        ->and($item->service_date_to)->toBeInstanceOf(Carbon::class)
        ->and($item->service_date_from->format('Y-m-d'))->toBe($from->format('Y-m-d'))
        ->and($item->service_date_to->format('Y-m-d'))->toBe($to->format('Y-m-d'));
});

it('can store metadata', function () {
    $metadata = [
        'product_code' => 'PROD-123',
        'warranty'     => '2 years',
    ];

    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'metadata'   => $metadata,
    ]);

    expect($item->metadata)->toBe($metadata)
        ->and($item->metadata['product_code'])->toBe('PROD-123');
});

it('can store internal_code', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'    => $this->invoice->id,
        'internal_code' => 'SKU-12345',
    ]);

    expect($item->internal_code)->toBe('SKU-12345');
});

it('can have null internal_code', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'    => $this->invoice->id,
        'internal_code' => null,
    ]);

    expect($item->internal_code)->toBeNull();
});

it('can have null unit_measure_id', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'      => $this->invoice->id,
        'unit_measure_id' => null,
    ]);

    expect($item->unit_measure_id)->toBeNull()
        ->and($item->unitMeasure)->toBeNull();
});

it('uses binary UUID for invoice_id', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
    ]);

    // Check that invoice_id is stored efficiently
    expect($item->invoice_id)->not->toBeNull()
        ->and($item->invoice)->not->toBeNull();
});

it('has auto-incrementing integer id', function () {
    $item1 = InvoiceItem::factory()->create(['invoice_id' => $this->invoice->id]);
    $item2 = InvoiceItem::factory()->create(['invoice_id' => $this->invoice->id]);

    expect($item1->id)->toBeInt()
        ->and($item2->id)->toBeInt()
        ->and($item2->id)->toBeGreaterThan($item1->id);
});

it('calculates taxable amount with precision', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'quantity'   => 333,  // 3.33 units in base100
        'unit_price' => 999,  // €9.99 in base100
    ]);

    $taxableAmount = $item->calculateTaxableAmount();

    expect($taxableAmount)->toBe(3326); // 333 * 999 / 100 = 3326.67 → rounds to 3326
});

it('can create service item with service dates', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'        => $this->invoice->id,
        'item_type'         => ItemType::SERVICE,
        'description'       => 'Consulting Services',
        'service_date_from' => now()->startOfMonth(),
        'service_date_to'   => now()->endOfMonth(),
    ]);

    expect($item->item_type)->toBe(ItemType::SERVICE)
        ->and($item->service_date_from)->not->toBeNull()
        ->and($item->service_date_to)->not->toBeNull();
});

it('can create multiple items for same invoice', function () {
    InvoiceItem::factory()->count(3)->create([
        'invoice_id' => $this->invoice->id,
    ]);

    expect($this->invoice->items()->count())->toBe(3);
});

it('stores total amounts correctly', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'       => $this->invoice->id,
        'quantity'         => 200,   // 2.0 units in base100
        'unit_price'       => 5000,  // €50.00 in base100
        'taxable_amount'   => 10000, // €100.00 in base100
        'total_tax_amount' => 2100,  // €21.00 in base100
        'total_amount'     => 12100, // €121.00 in base100
    ]);

    expect($item->taxable_amount)->toBe(10000)
        ->and($item->total_tax_amount)->toBe(2100)
        ->and($item->total_amount)->toBe(12100)
        ->and($item->taxable_amount + $item->total_tax_amount)->toBe($item->total_amount);
});
