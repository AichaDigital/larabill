<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\TaxRate;

it('can perform CRUD operations on tax rates', function () {
    // Create
    $taxRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA General',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(21.0),
        'is_active'    => true,
    ]);

    expect($taxRate->exists)->toBeTrue();
    expect($taxRate->id)->not->toBeNull();
    expect($taxRate->country_code)->toBe('ES');
    expect($taxRate->getRateAsPercentage())->toBe(21.0);

    // Read
    $foundTaxRate = TaxRate::find($taxRate->id);
    expect($foundTaxRate->tax_name)->toBe('IVA General');
    expect($foundTaxRate->is_active)->toBeTrue();

    // Update
    $foundTaxRate->update([
        'rate'     => TaxRate::percentageToBase100(22.0),
        'tax_name' => 'Updated IVA',
    ]);

    $updatedTaxRate = TaxRate::find($taxRate->id);
    expect($updatedTaxRate->getRateAsPercentage())->toBe(22.0);
    expect($updatedTaxRate->tax_name)->toBe('Updated IVA');

    // Delete
    $updatedTaxRate->delete();
    expect(TaxRate::find($taxRate->id))->toBeNull();
});

it('can validate tax rate data', function () {
    // Test required fields
    expect(fn () => TaxRate::create([]))
        ->toThrow(Exception::class);

    // Test valid country code
    $taxRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'Test Rate',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(21.0),
        'is_active'    => true,
    ]);

    expect($taxRate->country_code)->toBe('ES');

    // Test valid rate range
    $taxRate->update(['rate' => TaxRate::percentageToBase100(0.0)]); // Minimum rate
    expect($taxRate->getRateAsPercentage())->toBe(0.0);

    $taxRate->update(['rate' => TaxRate::percentageToBase100(100.0)]); // Maximum rate
    expect($taxRate->getRateAsPercentage())->toBe(100.0);
});

it('can configure tax rates by country', function () {
    // Create tax rates for different countries
    $spainRates = [
        ['country_code' => 'ES', 'country_name' => 'Spain', 'tax_name' => 'IVA General', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(21.0), 'is_active' => true],
        ['country_code' => 'ES', 'country_name' => 'Spain', 'tax_name' => 'IVA Reducido', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(10.0), 'is_active' => true],
        ['country_code' => 'ES', 'country_name' => 'Spain', 'tax_name' => 'IVA Superreducido', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(4.0), 'is_active' => true],
    ];

    $franceRates = [
        ['country_code' => 'FR', 'country_name' => 'France', 'tax_name' => 'TVA Standard', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(20.0), 'is_active' => true],
        ['country_code' => 'FR', 'country_name' => 'France', 'tax_name' => 'TVA Réduite', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(10.0), 'is_active' => true],
    ];

    foreach ($spainRates as $rateData) {
        TaxRate::create($rateData);
    }

    foreach ($franceRates as $rateData) {
        TaxRate::create($rateData);
    }

    // Test filtering by country
    $spainTaxRates = TaxRate::where('country_code', 'ES')->get();
    expect($spainTaxRates)->toHaveCount(3);

    $franceTaxRates = TaxRate::where('country_code', 'FR')->get();
    expect($franceTaxRates)->toHaveCount(2);

    // Test active rates only
    $activeSpainRates = TaxRate::where('country_code', 'ES')
        ->where('is_active', true)
        ->get();
    expect($activeSpainRates)->toHaveCount(3);
});

it('can handle tax rate import functionality', function () {
    // Simulate importing tax rates from a data source
    $importData = [
        ['country_code' => 'DE', 'country_name' => 'Germany', 'tax_name' => 'MwSt Standard', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(19.0), 'is_active' => true],
        ['country_code' => 'DE', 'country_name' => 'Germany', 'tax_name' => 'MwSt Reduziert', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(7.0), 'is_active' => true],
        ['country_code' => 'IT', 'country_name' => 'Italy', 'tax_name' => 'IVA Standard', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(22.0), 'is_active' => true],
        ['country_code' => 'IT', 'country_name' => 'Italy', 'tax_name' => 'IVA Ridotta', 'tax_type' => 'vat', 'rate' => TaxRate::percentageToBase100(10.0), 'is_active' => true],
    ];

    $importedCount = 0;
    foreach ($importData as $rateData) {
        $taxRate = TaxRate::create($rateData);
        $importedCount++;
        expect($taxRate->exists)->toBeTrue();
    }

    expect($importedCount)->toBe(4);

    // Verify imported data
    $germanRates = TaxRate::where('country_code', 'DE')->get();
    expect($germanRates)->toHaveCount(2);

    $italianRates = TaxRate::where('country_code', 'IT')->get();
    expect($italianRates)->toHaveCount(2);
});

it('can manage tax rate categories and special cases', function () {
    // Create standard tax rates
    $standardRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA General',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(21.0),
        'is_active'    => true,
    ]);

    // Create reduced tax rates
    $reducedRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA Reducido',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(10.0),
        'is_active'    => true,
    ]);

    // Create super-reduced tax rates
    $superReducedRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA Superreducido',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(4.0),
        'is_active'    => true,
    ]);

    // Create zero rate
    $zeroRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA Exento',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(0.0),
        'is_active'    => true,
    ]);

    // Test rate ordering
    $allRates = TaxRate::where('country_code', 'ES')
        ->orderBy('rate', 'desc')
        ->get();

    expect($allRates->first()->getRateAsPercentage())->toBe(21.0);
    expect($allRates->last()->getRateAsPercentage())->toBe(0.0);
});

it('can handle tax rate validation and constraints', function () {
    // Test duplicate rate prevention (if implemented)
    $firstRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA General',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(21.0),
        'is_active'    => true,
    ]);

    // Test rate validation
    expect($firstRate->getRateAsPercentage())->toBeGreaterThanOrEqual(0.0);
    expect($firstRate->getRateAsPercentage())->toBeLessThanOrEqual(100.0);

    // Test country code validation
    expect($firstRate->country_code)->toMatch('/^[A-Z]{2}$/');

    // Test tax name validation
    expect($firstRate->tax_name)->not->toBeEmpty();
    expect(strlen($firstRate->tax_name))->toBeLessThanOrEqual(255);
});

it('can handle tax rate activation and deactivation', function () {
    $taxRate = TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name'     => 'IVA General',
        'tax_type'     => 'vat',
        'rate'         => TaxRate::percentageToBase100(21.0),
        'is_active'    => true,
    ]);

    expect($taxRate->is_active)->toBeTrue();

    // Deactivate
    $taxRate->update(['is_active' => false]);
    expect($taxRate->is_active)->toBeFalse();

    // Reactivate
    $taxRate->update(['is_active' => true]);
    expect($taxRate->is_active)->toBeTrue();
});

it('can handle tax rate with special conditions', function () {
    // Create tax rate with special conditions
    $taxRate = TaxRate::create([
        'country_code'       => 'ES',
        'country_name'       => 'Spain',
        'tax_name'           => 'IVA General',
        'tax_type'           => 'vat',
        'rate'               => TaxRate::percentageToBase100(21.0),
        'special_conditions' => ['applies_to' => 'standard_goods', 'notes' => 'Standard VAT rate with special conditions'],
        'is_active'          => true,
    ]);

    // Test special conditions (if implemented)
    expect($taxRate->exists)->toBeTrue();
    expect($taxRate->special_conditions)->toHaveKey('notes');
    expect($taxRate->special_conditions['notes'])->toContain('special conditions');
});

it('can handle tax rate bulk operations', function () {
    // Bulk create tax rates
    $bulkData = [];
    for ($i = 1; $i <= 10; $i++) {
        $bulkData[] = [
            'country_code' => 'ES',
            'country_name' => 'Spain',
            'tax_name'     => "Test Rate {$i}",
            'tax_type'     => 'vat',
            'rate'         => TaxRate::percentageToBase100(21.0 + $i),
            'is_active'    => true,
        ];
    }

    $createdRates = [];
    foreach ($bulkData as $rateData) {
        $createdRates[] = TaxRate::create($rateData);
    }

    expect($createdRates)->toHaveCount(10);

    // Bulk update
    $updatedCount = TaxRate::where('country_code', 'ES')
        ->where('tax_name', 'like', 'Test Rate%')
        ->update(['is_active' => false]);

    expect($updatedCount)->toBe(10);

    // Verify bulk update
    $inactiveRates = TaxRate::where('country_code', 'ES')
        ->where('tax_name', 'like', 'Test Rate%')
        ->where('is_active', false)
        ->get();

    expect($inactiveRates)->toHaveCount(10);
});
