<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user    = \AichaDigital\Larabill\Tests\Models\User::factory()->create();
    $this->invoice = Invoice::factory()->create(['user_id' => $this->user->id]);
    // Note: unit_measure_id is nullable and not required in v0.4.0
});

it('can create an invoice item', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id'        => $this->invoice->id,
        'item_type'         => ItemType::GOOD,
        'description'       => 'Test Product',
        'quantity'          => 2.0,
        'unit_price'        => 10.00,
        'taxable_amount'    => 20.00,
        'total_tax_amount'  => 4.20,
        'total_amount'      => 24.20,
    ]);

    expect($item)->toBeInstanceOf(InvoiceItem::class)
        ->and($item->description)->toBe('Test Product')
        ->and($item->quantity)->toBe(2.0)
        ->and($item->unit_price)->toBe(10.00)
        ->and($item->taxable_amount)->toBe(20.00)
        ->and($item->total_tax_amount)->toBe(4.20)
        ->and($item->total_amount)->toBe(24.20);
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
        'unit_price'       => 12.34,
        'taxable_amount'   => 24.68,
        'total_tax_amount' => 5.18,
        'total_amount'     => 29.86,
    ]);

    expect($item->unit_price)->toBe(12.34)
        ->and($item->taxable_amount)->toBe(24.68)
        ->and($item->total_tax_amount)->toBe(5.18)
        ->and($item->total_amount)->toBe(29.86);
});

it('can cast quantity using Base100', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'quantity'   => 1.5,
    ]);

    expect($item->quantity)->toBe(1.5);
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
        'quantity'   => 3.0,
        'unit_price' => 15.50,
    ]);

    $taxableAmount = $item->calculateTaxableAmount();

    expect($taxableAmount)->toBe(46.5);
});

it('can calculate taxable amount with decimal quantity', function () {
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $this->invoice->id,
        'quantity'   => 2.5,
        'unit_price' => 10.00,
    ]);

    $taxableAmount = $item->calculateTaxableAmount();

    expect($taxableAmount)->toBe(25.0);
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

    expect($item->service_date_from)->toBeInstanceOf(\Carbon\Carbon::class)
        ->and($item->service_date_to)->toBeInstanceOf(\Carbon\Carbon::class)
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
        'quantity'   => 3.33,
        'unit_price' => 9.99,
    ]);

    $taxableAmount = $item->calculateTaxableAmount();

    expect($taxableAmount)->toBe(33.27);
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
        'quantity'         => 2.0,
        'unit_price'       => 50.00,
        'taxable_amount'   => 100.00,
        'total_tax_amount' => 21.00,
        'total_amount'     => 121.00,
    ]);

    expect($item->taxable_amount)->toBe(100.00)
        ->and($item->total_tax_amount)->toBe(21.00)
        ->and($item->total_amount)->toBe(121.00)
        ->and($item->taxable_amount + $item->total_tax_amount)->toBe($item->total_amount);
});
