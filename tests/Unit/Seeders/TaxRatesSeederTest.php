<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\TaxRate;

it('can create Spanish tax rates with factories', function () {
    // Preparación: crear las tax rates usando factories
    $generalRate      = TaxRate::factory()->spanishGeneral()->create();
    $reducedRate      = TaxRate::factory()->spanishReduced()->create();
    $superReducedRate = TaxRate::factory()->spanishSuperReduced()->create();

    // Verificación: comprobar que se crearon correctamente
    $spanishRates = TaxRate::where('country_code', 'ES')->get();

    expect($spanishRates)->toHaveCount(3);

    expect($generalRate->tax_name)->toBe('IVA General');
    expect($generalRate->rate)->toBe('0.2100');

    expect($reducedRate->tax_name)->toBe('IVA Reducido');
    expect($reducedRate->rate)->toBe('0.1000');

    expect($superReducedRate->tax_name)->toBe('IVA Superreducido');
    expect($superReducedRate->rate)->toBe('0.0400');
});

it('can create EU tax rates with factories', function () {
    // Preparación: crear las tax rates usando factories
    $germanRate = TaxRate::factory()->german()->create();
    $frenchRate = TaxRate::factory()->french()->create();

    // Verificación: comprobar que se crearon correctamente
    $euRates = TaxRate::whereIn('country_code', ['DE', 'FR'])->get();

    expect($euRates)->toHaveCount(2);

    expect($germanRate->country_code)->toBe('DE');
    expect($germanRate->tax_name)->toBe('MwSt');
    expect($germanRate->rate)->toBe('0.1900');

    expect($frenchRate->country_code)->toBe('FR');
    expect($frenchRate->tax_name)->toBe('TVA');
    expect($frenchRate->rate)->toBe('0.2000');
});

it('can create special territories rates with factories', function () {
    // Preparación: crear las tax rates usando factories
    $canaryRate  = TaxRate::factory()->canaryIslands()->create();
    $ceutaRate   = TaxRate::factory()->ceuta()->create();
    $melillaRate = TaxRate::factory()->melilla()->create();

    // Verificación: comprobar que se crearon correctamente
    $specialRates = TaxRate::whereIn('country_code', ['IC', 'CE', 'ML'])->get();

    expect($specialRates)->toHaveCount(3);

    expect($canaryRate->country_code)->toBe('IC');
    expect($canaryRate->tax_name)->toBe('IGIC');
    expect($canaryRate->rate)->toBe('0.0700');
    expect($canaryRate->special_conditions)->toHaveKey('exempt_from_spanish_vat');

    expect($ceutaRate->country_code)->toBe('CE');
    expect($ceutaRate->tax_name)->toBe('IPSI');
    expect($ceutaRate->rate)->toBe('0.0000');
    expect($ceutaRate->special_conditions)->toHaveKey('exempt_from_spanish_vat');

    expect($melillaRate->country_code)->toBe('ML');
    expect($melillaRate->tax_name)->toBe('IPSI');
    expect($melillaRate->rate)->toBe('0.0000');
    expect($melillaRate->special_conditions)->toHaveKey('exempt_from_spanish_vat');
});
