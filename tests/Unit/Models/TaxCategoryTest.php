<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\TaxCategory;

it('can create a tax category', function () {
    $taxCategory = TaxCategory::create([
        'name'        => 'Standard VAT (ES)',
        'code'        => 'VAT_ES_STANDARD',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 2100, // 21%
        'is_default'  => true,
        'is_active'   => true,
        'description' => 'Standard VAT rate for Spain',
    ]);

    expect($taxCategory->exists)->toBeTrue();
    expect($taxCategory->name)->toBe('Standard VAT (ES)');
    expect($taxCategory->code)->toBe('VAT_ES_STANDARD');
    expect($taxCategory->tax_type)->toBe('vat');
    expect($taxCategory->region_code)->toBe('ES');
    expect($taxCategory->rate)->toBe(21.0); // Base100 cast returns float
    expect($taxCategory->is_default)->toBeTrue();
    expect($taxCategory->is_active)->toBeTrue();
});

it('can scope active tax categories', function () {
    TaxCategory::create([
        'name'        => 'Active Category',
        'code'        => 'ACTIVE_1',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 2100,
        'is_active'   => true,
    ]);

    TaxCategory::create([
        'name'        => 'Inactive Category',
        'code'        => 'INACTIVE_1',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 1000,
        'is_active'   => false,
    ]);

    $active = TaxCategory::where('is_active', true)->get();
    expect($active)->toHaveCount(1);
    expect($active->first()->code)->toBe('ACTIVE_1');
});

it('can filter by tax type', function () {
    TaxCategory::create([
        'name'        => 'VAT Standard',
        'code'        => 'VAT_1',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 2100,
    ]);

    TaxCategory::create([
        'name'        => 'Sales Tax',
        'code'        => 'SALES_1',
        'tax_type'    => 'sales_tax',
        'region_code' => 'US-CA',
        'rate'        => 725,
    ]);

    $vatCategories = TaxCategory::where('tax_type', 'vat')->get();
    expect($vatCategories)->toHaveCount(1);
    expect($vatCategories->first()->code)->toBe('VAT_1');
});

it('can filter by region code', function () {
    TaxCategory::create([
        'name'        => 'Spain VAT',
        'code'        => 'VAT_ES',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 2100,
    ]);

    TaxCategory::create([
        'name'        => 'Germany VAT',
        'code'        => 'VAT_DE',
        'tax_type'    => 'vat',
        'region_code' => 'DE',
        'rate'        => 1900,
    ]);

    $spainCategories = TaxCategory::where('region_code', 'ES')->get();
    expect($spainCategories)->toHaveCount(1);
    expect($spainCategories->first()->code)->toBe('VAT_ES');
});

it('can get default tax category for a region', function () {
    TaxCategory::create([
        'name'        => 'Standard',
        'code'        => 'VAT_ES_STD',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 2100,
        'is_default'  => true,
    ]);

    TaxCategory::create([
        'name'        => 'Reduced',
        'code'        => 'VAT_ES_RED',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 1000,
        'is_default'  => false,
    ]);

    $defaultCategory = TaxCategory::where('region_code', 'ES')
        ->where('is_default', true)
        ->first();

    expect($defaultCategory)->not->toBeNull();
    expect($defaultCategory->code)->toBe('VAT_ES_STD');
    expect($defaultCategory->rate)->toBe(21.0);
});

it('stores rate as base-100 integer', function () {
    $taxCategory = TaxCategory::create([
        'name'        => 'Test Rate',
        'code'        => 'TEST_RATE',
        'tax_type'    => 'vat',
        'region_code' => 'ES',
        'rate'        => 21.50, // Input as float
    ]);

    // Verify it's stored correctly and retrieved as float
    expect($taxCategory->rate)->toBe(21.5);

    // Reload from database
    $reloaded = TaxCategory::find($taxCategory->id);
    expect($reloaded->rate)->toBe(21.5);
});

it('can handle multiple tax categories for same region', function () {
    $categories = [
        ['name' => 'Standard', 'code' => 'VAT_ES_STD', 'rate' => 2100, 'is_default' => true],
        ['name' => 'Reduced', 'code' => 'VAT_ES_RED', 'rate' => 1000, 'is_default' => false],
        ['name' => 'Super Reduced', 'code' => 'VAT_ES_SR', 'rate' => 400, 'is_default' => false],
        ['name' => 'Exempt', 'code' => 'VAT_ES_EX', 'rate' => 0, 'is_default' => false],
    ];

    foreach ($categories as $category) {
        TaxCategory::create(array_merge($category, [
            'tax_type'    => 'vat',
            'region_code' => 'ES',
        ]));
    }

    $esCategories = TaxCategory::where('region_code', 'ES')->get();
    expect($esCategories)->toHaveCount(4);
    expect($esCategories->where('is_default', true)->count())->toBe(1);
});

it('can support different tax systems', function () {
    $systems = [
        ['name' => 'VAT Standard', 'code' => 'VAT_ES', 'tax_type' => 'vat', 'region' => 'ES', 'rate' => 2100],
        ['name' => 'Sales Tax CA', 'code' => 'SALES_CA', 'tax_type' => 'sales_tax', 'region' => 'US-CA', 'rate' => 725],
        ['name' => 'GST Australia', 'code' => 'GST_AU', 'tax_type' => 'gst', 'region' => 'AU', 'rate' => 1000],
        ['name' => 'HST Ontario', 'code' => 'HST_ON', 'tax_type' => 'hst', 'region' => 'CA-ON', 'rate' => 1300],
    ];

    foreach ($systems as $system) {
        TaxCategory::create([
            'name'        => $system['name'],
            'code'        => $system['code'],
            'tax_type'    => $system['tax_type'],
            'region_code' => $system['region'],
            'rate'        => $system['rate'],
        ]);
    }

    expect(TaxCategory::where('tax_type', 'vat')->count())->toBe(1);
    expect(TaxCategory::where('tax_type', 'sales_tax')->count())->toBe(1);
    expect(TaxCategory::where('tax_type', 'gst')->count())->toBe(1);
    expect(TaxCategory::where('tax_type', 'hst')->count())->toBe(1);
});

