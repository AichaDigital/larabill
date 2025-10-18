<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{TaxGroup, TaxRate};

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('can be created using a factory', function () {
    $taxGroup = TaxGroup::factory()->create([
        'name' => 'Impuestos Estándar',
    ]);

    expect($taxGroup)->toBeInstanceOf(TaxGroup::class);
    expect($taxGroup->name)->toBe('Impuestos Estándar');
});

it('can have many tax rates', function () {
    $taxGroup = TaxGroup::factory()->create();
    $taxRate1 = TaxRate::factory()->create(['rate' => 1000]);
    $taxRate2 = TaxRate::factory()->create(['rate' => 2100]);

    $taxGroup->taxRates()->attach([$taxRate1->id, $taxRate2->id]);

    expect($taxGroup->taxRates)->toHaveCount(2);
});
