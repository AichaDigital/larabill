<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\TaxType;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a tax group', function () {
    $taxGroup = TaxGroup::factory()->create([
        'name'        => 'Servicios Digitales UE',
        'description' => 'Tax group for EU digital services',
    ]);

    expect($taxGroup)->toBeInstanceOf(TaxGroup::class)
        ->and($taxGroup->name)->toBe('Servicios Digitales UE')
        ->and($taxGroup->description)->toBe('Tax group for EU digital services');
});

it('can have null description', function () {
    $taxGroup = TaxGroup::factory()->create([
        'description' => null,
    ]);

    expect($taxGroup->description)->toBeNull();
});

it('can have many tax rates', function () {
    $taxGroup = TaxGroup::factory()->create();
    $rate1    = TaxRate::factory()->create(['name' => 'VAT']);
    $rate2    = TaxRate::factory()->create(['name' => 'GST']);

    $taxGroup->taxRates()->attach($rate1->id, ['priority' => 1]);
    $taxGroup->taxRates()->attach($rate2->id, ['priority' => 2]);

    expect($taxGroup->taxRates)->toHaveCount(2)
        ->and($taxGroup->taxRates->first())->toBeInstanceOf(TaxRate::class);
});

it('orders tax rates by priority', function () {
    $taxGroup = TaxGroup::factory()->create();
    $rate1    = TaxRate::factory()->create(['name' => 'Rate 1']);
    $rate2    = TaxRate::factory()->create(['name' => 'Rate 2']);
    $rate3    = TaxRate::factory()->create(['name' => 'Rate 3']);

    $taxGroup->taxRates()->attach($rate1->id, ['priority' => 3]);
    $taxGroup->taxRates()->attach($rate2->id, ['priority' => 1]);
    $taxGroup->taxRates()->attach($rate3->id, ['priority' => 2]);

    $rates = $taxGroup->taxRates;

    expect($rates[0]->name)->toBe('Rate 2') // Priority 1
        ->and($rates[1]->name)->toBe('Rate 3') // Priority 2
        ->and($rates[2]->name)->toBe('Rate 1'); // Priority 3
});

it('can access pivot data', function () {
    $taxGroup = TaxGroup::factory()->create();
    $taxRate  = TaxRate::factory()->create();

    $taxGroup->taxRates()->attach($taxRate->id, ['priority' => 5]);

    $firstRate = $taxGroup->taxRates->first();
    expect($firstRate->pivot->priority)->toBe(5);
});

it('can update tax group name', function () {
    $taxGroup = TaxGroup::factory()->create([
        'name' => 'Old Name',
    ]);

    $taxGroup->update(['name' => 'New Name']);

    expect($taxGroup->fresh()->name)->toBe('New Name');
});

it('can update tax group description', function () {
    $taxGroup = TaxGroup::factory()->create([
        'description' => 'Old description',
    ]);

    $taxGroup->update(['description' => 'New description']);

    expect($taxGroup->fresh()->description)->toBe('New description');
});

it('can detach tax rates', function () {
    $taxGroup = TaxGroup::factory()->create();
    $taxRate  = TaxRate::factory()->create();

    $taxGroup->taxRates()->attach($taxRate->id, ['priority' => 1]);
    expect($taxGroup->taxRates)->toHaveCount(1);

    $taxGroup->taxRates()->detach($taxRate->id);
    expect($taxGroup->fresh()->taxRates)->toHaveCount(0);
});

it('can attach multiple tax rates at once', function () {
    $taxGroup = TaxGroup::factory()->create();
    $rates    = TaxRate::factory()->count(3)->create();

    $taxGroup->taxRates()->attach([
        $rates[0]->id => ['priority' => 1],
        $rates[1]->id => ['priority' => 2],
        $rates[2]->id => ['priority' => 3],
    ]);

    expect($taxGroup->taxRates)->toHaveCount(3);
});

it('has timestamps', function () {
    $taxGroup = TaxGroup::factory()->create();

    expect($taxGroup->created_at)->toBeInstanceOf(Carbon::class)
        ->and($taxGroup->updated_at)->toBeInstanceOf(Carbon::class);
});

it('can sync tax rates', function () {
    $taxGroup = TaxGroup::factory()->create();
    $rate1    = TaxRate::factory()->create();
    $rate2    = TaxRate::factory()->create();
    $rate3    = TaxRate::factory()->create();

    // Attach initial rates
    $taxGroup->taxRates()->attach([
        $rate1->id => ['priority' => 1],
        $rate2->id => ['priority' => 2],
    ]);

    expect($taxGroup->taxRates)->toHaveCount(2);

    // Sync to new set
    $taxGroup->taxRates()->sync([
        $rate2->id => ['priority' => 1],
        $rate3->id => ['priority' => 2],
    ]);

    expect($taxGroup->fresh()->taxRates)->toHaveCount(2)
        ->and($taxGroup->fresh()->taxRates->pluck('id')->toArray())->toContain($rate2->id)
        ->and($taxGroup->fresh()->taxRates->pluck('id')->toArray())->toContain($rate3->id)
        ->and($taxGroup->fresh()->taxRates->pluck('id')->toArray())->not->toContain($rate1->id);
});

it('can create tax group for composite taxes', function () {
    $taxGroup = TaxGroup::factory()->create([
        'name'        => 'Venta Estándar en Boston',
        'description' => 'MA state tax + Boston city surcharge',
    ]);

    $stateTax = TaxRate::factory()->create([
        'name'   => 'MA State Tax',
        'rate'   => 625,
        'type'   => TaxType::SALES_TAX,
        'region' => 'US-MA',
    ]);

    $cityTax = TaxRate::factory()->create([
        'name'   => 'Boston City Surcharge',
        'rate'   => 50,
        'type'   => TaxType::SALES_TAX,
        'region' => 'US-MA-BOSTON',
    ]);

    $taxGroup->taxRates()->attach([
        $stateTax->id => ['priority' => 1],
        $cityTax->id  => ['priority' => 2],
    ]);

    expect($taxGroup->taxRates)->toHaveCount(2)
        ->and($taxGroup->taxRates->sum('rate'))->toBe(675);
});

it('maintains separate tax groups for different jurisdictions', function () {
    $groupSpain = TaxGroup::factory()->create(['name' => 'IVA General España']);
    $groupUS    = TaxGroup::factory()->create(['name' => 'MA Sales Tax']);

    $vatES = TaxRate::factory()->create([
        'rate'   => 2100,
        'type'   => TaxType::VAT,
        'region' => 'ES',
    ]);

    $salesTaxMA = TaxRate::factory()->create([
        'rate'   => 625,
        'type'   => TaxType::SALES_TAX,
        'region' => 'US-MA',
    ]);

    $groupSpain->taxRates()->attach($vatES->id, ['priority' => 1]);
    $groupUS->taxRates()->attach($salesTaxMA->id, ['priority' => 1]);

    expect($groupSpain->taxRates)->toHaveCount(1)
        ->and($groupUS->taxRates)->toHaveCount(1)
        ->and($groupSpain->taxRates->first()->type)->toBe(TaxType::VAT)
        ->and($groupUS->taxRates->first()->type)->toBe(TaxType::SALES_TAX);
});

it('can query tax groups by name', function () {
    TaxGroup::factory()->create(['name' => 'Group A']);
    TaxGroup::factory()->create(['name' => 'Group B']);

    $group = TaxGroup::where('name', 'Group A')->first();

    expect($group)->not->toBeNull()
        ->and($group->name)->toBe('Group A');
});

it('can count tax rates in group', function () {
    $taxGroup = TaxGroup::factory()->create();
    $rates    = TaxRate::factory()->count(5)->create();

    foreach ($rates as $index => $rate) {
        $taxGroup->taxRates()->attach($rate->id, ['priority' => $index + 1]);
    }

    expect($taxGroup->taxRates()->count())->toBe(5);
});

it('can check if group has specific tax rate', function () {
    $taxGroup = TaxGroup::factory()->create();
    $rate1    = TaxRate::factory()->create();
    $rate2    = TaxRate::factory()->create();

    $taxGroup->taxRates()->attach($rate1->id, ['priority' => 1]);

    $hasRate1 = $taxGroup->taxRates->contains($rate1->id);
    $hasRate2 = $taxGroup->taxRates->contains($rate2->id);

    expect($hasRate1)->toBeTrue()
        ->and($hasRate2)->toBeFalse();
});
