<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{TaxGroup, TaxRate};

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('can be created using a factory', function () {
    $taxRate = TaxRate::factory()->create([
        'name' => 'IVA General',
        'rate' => 2100,
    ]);

    expect($taxRate)->toBeInstanceOf(TaxRate::class);
    expect($taxRate->name)->toBe('IVA General');
    expect($taxRate->rate)->toBe(2100);
});

it('can belong to many tax groups', function () {
    $taxRate   = TaxRate::factory()->create();
    $taxGroup1 = TaxGroup::factory()->create(['name' => 'Group 1']);
    $taxGroup2 = TaxGroup::factory()->create(['name' => 'Group 2']);

    $taxRate->taxGroups()->attach([$taxGroup1->id, $taxGroup2->id]);

    expect($taxRate->taxGroups)->toHaveCount(2);
    expect($taxRate->taxGroups->first()->name)->toBe('Group 1');
});
