<?php

declare(strict_types=1);

use AichaDigital\Larabill\Database\Seeders\TaxRatesSeeder;
use AichaDigital\Larabill\Models\TaxRate;

it('can seed Spanish tax rates', function () {
    $seeder = new TaxRatesSeeder;
    $seeder->run();

    $spanishRates = TaxRate::where('country_code', 'ES')->get();

    expect($spanishRates)->toHaveCount(3);

    $generalRate = $spanishRates->where('tax_name', 'IVA General')->first();
    expect($generalRate)->not->toBeNull();
    expect($generalRate->rate)->toBe('0.2100');

    $reducedRate = $spanishRates->where('tax_name', 'IVA Reducido')->first();
    expect($reducedRate)->not->toBeNull();
    expect($reducedRate->rate)->toBe('0.1000');

    $superReducedRate = $spanishRates->where('tax_name', 'IVA Superreducido')->first();
    expect($superReducedRate)->not->toBeNull();
    expect($superReducedRate->rate)->toBe('0.0400');
});

it('can seed EU tax rates', function () {
    $seeder = new TaxRatesSeeder;
    $seeder->run();

    $euCountries = ['DE', 'FR', 'IT', 'NL', 'PT', 'BE', 'AT', 'IE', 'FI', 'SE'];
    $euRates = TaxRate::whereIn('country_code', $euCountries)->get();

    expect($euRates)->toHaveCount(10);

    $germanRate = $euRates->where('country_code', 'DE')->first();
    expect($germanRate)->not->toBeNull();
    expect($germanRate->tax_name)->toBe('MwSt');
    expect($germanRate->rate)->toBe('0.1900');

    $frenchRate = $euRates->where('country_code', 'FR')->first();
    expect($frenchRate)->not->toBeNull();
    expect($frenchRate->tax_name)->toBe('TVA');
    expect($frenchRate->rate)->toBe('0.2000');
});

it('can seed special territories rates', function () {
    $seeder = new TaxRatesSeeder;
    $seeder->run();

    $specialRates = TaxRate::whereIn('country_code', ['IC', 'CE', 'ML'])->get();

    expect($specialRates)->toHaveCount(3);

    $canaryRate = $specialRates->where('country_code', 'IC')->first();
    expect($canaryRate)->not->toBeNull();
    expect($canaryRate->tax_name)->toBe('IGIC');
    expect($canaryRate->rate)->toBe('0.0700');
    expect($canaryRate->special_conditions)->toHaveKey('exempt_from_spanish_vat');

    $ceutaRate = $specialRates->where('country_code', 'CE')->first();
    expect($ceutaRate)->not->toBeNull();
    expect($ceutaRate->tax_name)->toBe('IPSI');
    expect($ceutaRate->rate)->toBe('0.0000');

    $melillaRate = $specialRates->where('country_code', 'ML')->first();
    expect($melillaRate)->not->toBeNull();
    expect($melillaRate->tax_name)->toBe('IPSI');
    expect($melillaRate->rate)->toBe('0.0000');
});
