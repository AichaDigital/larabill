<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\TaxCategory;

it('can create a tax category', function () {
    $taxCategory = TaxCategory::create([
        'code'          => 'VAT_ES_STANDARD',
        'name'          => 'Standard VAT (ES)',
        'tax_type'      => 'vat',
        'country_code'  => 'ES',
        'default_rate'  => 21.0, // 21%
        'is_active'     => true,
    ]);

    expect($taxCategory->exists)->toBeTrue();
    expect($taxCategory->code)->toBe('VAT_ES_STANDARD');
    expect($taxCategory->name)->toBe('Standard VAT (ES)');
    expect($taxCategory->tax_type)->toBe('vat');
    expect($taxCategory->country_code)->toBe('ES');
    expect($taxCategory->default_rate)->toBe(21.0); // Base100 cast returns float
    expect($taxCategory->is_active)->toBeTrue();
});

it('can scope active tax categories', function () {
    TaxCategory::create([
        'code'         => 'ACTIVE_1',
        'name'         => 'Active Category',
        'tax_type'     => 'vat',
        'country_code' => 'ES',
        'default_rate' => 21.0,
        'is_active'    => true,
    ]);

    TaxCategory::create([
        'code'         => 'INACTIVE_1',
        'name'         => 'Inactive Category',
        'tax_type'     => 'vat',
        'country_code' => 'ES',
        'default_rate' => 10.0,
        'is_active'    => false,
    ]);

    $active = TaxCategory::active()->get();
    expect($active)->toHaveCount(1);
    expect($active->first()->code)->toBe('ACTIVE_1');
});

it('can filter by tax type', function () {
    TaxCategory::create([
        'code'         => 'VAT_1',
        'name'         => 'VAT Standard',
        'tax_type'     => 'vat',
        'country_code' => 'ES',
        'default_rate' => 21.0,
    ]);

    TaxCategory::create([
        'code'         => 'SALES_1',
        'name'         => 'Sales Tax',
        'tax_type'     => 'sales_tax',
        'country_code' => 'US',
        'region_code'  => 'CA',
        'default_rate' => 7.25,
    ]);

    $vatCategories = TaxCategory::taxType('vat')->get();
    expect($vatCategories)->toHaveCount(1);
    expect($vatCategories->first()->code)->toBe('VAT_1');
});

it('can filter by country code', function () {
    TaxCategory::create([
        'code'         => 'VAT_ES',
        'name'         => 'Spain VAT',
        'tax_type'     => 'vat',
        'country_code' => 'ES',
        'default_rate' => 21.0,
    ]);

    TaxCategory::create([
        'code'         => 'VAT_DE',
        'name'         => 'Germany VAT',
        'tax_type'     => 'vat',
        'country_code' => 'DE',
        'default_rate' => 19.0,
    ]);

    $spainCategories = TaxCategory::country('ES')->get();
    expect($spainCategories)->toHaveCount(1);
    expect($spainCategories->first()->code)->toBe('VAT_ES');
});

it('stores rate as base-100 integer', function () {
    $taxCategory = TaxCategory::create([
        'code'         => 'TEST_RATE',
        'name'         => 'Test Rate',
        'tax_type'     => 'vat',
        'country_code' => 'ES',
        'default_rate' => 21.50, // Input as float
    ]);

    // Verify it's stored correctly and retrieved as float
    expect($taxCategory->default_rate)->toBe(21.5);

    // Reload from database
    $reloaded = TaxCategory::find($taxCategory->id);
    expect($reloaded->default_rate)->toBe(21.5);
});

it('can support different tax systems', function () {
    $systems = [
        ['code' => 'VAT_ES', 'name' => 'VAT Standard', 'tax_type' => 'vat', 'country' => 'ES', 'rate' => 2100],
        ['code' => 'SALES_CA', 'name' => 'Sales Tax CA', 'tax_type' => 'sales_tax', 'country' => 'US', 'rate' => 725],
        ['code' => 'GST_AU', 'name' => 'GST Australia', 'tax_type' => 'gst', 'country' => 'AU', 'rate' => 1000],
        ['code' => 'HST_ON', 'name' => 'HST Ontario', 'tax_type' => 'hst', 'country' => 'CA', 'rate' => 1300],
    ];

    foreach ($systems as $system) {
        TaxCategory::create([
            'code'         => $system['code'],
            'name'         => $system['name'],
            'tax_type'     => $system['tax_type'],
            'country_code' => $system['country'],
            'default_rate' => $system['rate'],
        ]);
    }

    expect(TaxCategory::taxType('vat')->count())->toBe(1);
    expect(TaxCategory::taxType('sales_tax')->count())->toBe(1);
    expect(TaxCategory::taxType('gst')->count())->toBe(1);
    expect(TaxCategory::taxType('hst')->count())->toBe(1);
});

it('can filter by region', function () {
    TaxCategory::create([
        'code'         => 'SALES_CA',
        'name'         => 'Sales Tax CA',
        'tax_type'     => 'sales_tax',
        'country_code' => 'US',
        'region_code'  => 'CA',
        'default_rate' => 7.25,
    ]);

    TaxCategory::create([
        'code'         => 'SALES_NY',
        'name'         => 'Sales Tax NY',
        'tax_type'     => 'sales_tax',
        'country_code' => 'US',
        'region_code'  => 'NY',
        'default_rate' => 4.0,
    ]);

    $caCategories = TaxCategory::region('CA')->get();
    expect($caCategories)->toHaveCount(1);
    expect($caCategories->first()->code)->toBe('SALES_CA');
});

it('can order by sort_order', function () {
    TaxCategory::create(['code' => 'C', 'name' => 'Third', 'tax_type' => 'vat', 'country_code' => 'ES', 'default_rate' => 21.0, 'sort_order' => 3]);
    TaxCategory::create(['code' => 'A', 'name' => 'First', 'tax_type' => 'vat', 'country_code' => 'ES', 'default_rate' => 21.0, 'sort_order' => 1]);
    TaxCategory::create(['code' => 'B', 'name' => 'Second', 'tax_type' => 'vat', 'country_code' => 'ES', 'default_rate' => 21.0, 'sort_order' => 2]);

    $ordered = TaxCategory::ordered()->get();
    expect($ordered->first()->code)->toBe('A');
    expect($ordered->last()->code)->toBe('C');
});
