<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\TaxRate;

it('can create a tax rate', function () {
    $taxRate = new TaxRate([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name' => 'IVA',
        'tax_type' => 'VAT',
        'rate' => 0.21,
        'is_active' => true,
        'applies_to' => 'all',
        'special_conditions' => null,
    ]);

    expect($taxRate->country_code)->toBe('ES');
    expect($taxRate->country_name)->toBe('Spain');
    expect($taxRate->tax_name)->toBe('IVA');
    expect($taxRate->tax_type)->toBe('VAT');
    expect($taxRate->rate)->toBe('0.2100');
    expect($taxRate->is_active)->toBeTrue();
    expect($taxRate->applies_to)->toBe('all');
    expect($taxRate->special_conditions)->toBeNull();
});

it('can get Spanish tax rates', function () {
    // Create Spanish tax rates
    TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name' => 'IVA General',
        'tax_type' => 'VAT',
        'rate' => 0.21,
        'is_active' => true,
        'applies_to' => 'all',
    ]);

    TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name' => 'IVA Reducido',
        'tax_type' => 'VAT',
        'rate' => 0.10,
        'is_active' => true,
        'applies_to' => 'food',
    ]);

    $spanishRates = TaxRate::getSpanishRates();

    expect($spanishRates)->toHaveCount(2);
    expect($spanishRates->first()->country_code)->toBe('ES');
});

it('can get EU tax rates', function () {
    // Create EU tax rates
    TaxRate::create([
        'country_code' => 'DE',
        'country_name' => 'Germany',
        'tax_name' => 'MwSt',
        'tax_type' => 'VAT',
        'rate' => 0.19,
        'is_active' => true,
        'applies_to' => 'all',
    ]);

    TaxRate::create([
        'country_code' => 'FR',
        'country_name' => 'France',
        'tax_name' => 'TVA',
        'tax_type' => 'VAT',
        'rate' => 0.20,
        'is_active' => true,
        'applies_to' => 'all',
    ]);

    $euRates = TaxRate::getEURates();

    expect($euRates)->toHaveCount(2);
    expect($euRates->pluck('country_code'))->toContain('DE', 'FR');
});

it('can scope active tax rates', function () {
    TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name' => 'IVA Active',
        'tax_type' => 'VAT',
        'rate' => 0.21,
        'is_active' => true,
        'applies_to' => 'all',
    ]);

    TaxRate::create([
        'country_code' => 'ES',
        'country_name' => 'Spain',
        'tax_name' => 'IVA Inactive',
        'tax_type' => 'VAT',
        'rate' => 0.21,
        'is_active' => false,
        'applies_to' => 'food', // Different applies_to to avoid unique constraint
    ]);

    $activeRates = TaxRate::active()->get();

    expect($activeRates)->toHaveCount(1);
    expect($activeRates->first()->tax_name)->toBe('IVA Active');
});
