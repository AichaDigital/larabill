<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\TaxType;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a tax rate', function () {
    $taxRate = TaxRate::factory()->create([
        'name'   => 'IVA General',
        'rate'   => 2100,
        'region' => 'ES',
        'type'   => TaxType::VAT,
    ]);

    expect($taxRate)->toBeInstanceOf(TaxRate::class)
        ->and($taxRate->name)->toBe('IVA General')
        ->and($taxRate->rate)->toBe(2100)
        ->and($taxRate->region)->toBe('ES')
        ->and($taxRate->type)->toBe(TaxType::VAT);
});

it('can cast rate as integer', function () {
    $taxRate = TaxRate::factory()->create([
        'rate' => 2100,
    ]);

    expect($taxRate->rate)->toBeInt()
        ->and($taxRate->rate)->toBe(2100);
});

it('can cast type as enum', function () {
    $taxRate = TaxRate::factory()->create([
        'type' => TaxType::SALES_TAX,
    ]);

    expect($taxRate->type)->toBeInstanceOf(TaxType::class)
        ->and($taxRate->type)->toBe(TaxType::SALES_TAX);
});

it('can have null region', function () {
    $taxRate = TaxRate::factory()->create([
        'region' => null,
    ]);

    expect($taxRate->region)->toBeNull();
});

it('can belong to many tax groups', function () {
    $taxRate  = TaxRate::factory()->create();
    $taxGroup = TaxGroup::factory()->create();

    $taxRate->taxGroups()->attach($taxGroup->id, ['priority' => 1]);

    expect($taxRate->taxGroups)->toHaveCount(1)
        ->and($taxRate->taxGroups->first())->toBeInstanceOf(TaxGroup::class);
});

it('can have multiple tax groups with priorities', function () {
    $taxRate = TaxRate::factory()->create();
    $group1  = TaxGroup::factory()->create(['name' => 'Group 1']);
    $group2  = TaxGroup::factory()->create(['name' => 'Group 2']);

    $taxRate->taxGroups()->attach($group1->id, ['priority' => 2]);
    $taxRate->taxGroups()->attach($group2->id, ['priority' => 1]);

    $groups = $taxRate->taxGroups()->get();

    expect($groups)->toHaveCount(2)
        ->and($groups->first()->name)->toBe('Group 2') // Priority 1 first
        ->and($groups->last()->name)->toBe('Group 1'); // Priority 2 second
});

it('orders tax groups by priority', function () {
    $taxRate = TaxRate::factory()->create();
    $group1  = TaxGroup::factory()->create();
    $group2  = TaxGroup::factory()->create();
    $group3  = TaxGroup::factory()->create();

    $taxRate->taxGroups()->attach($group1->id, ['priority' => 3]);
    $taxRate->taxGroups()->attach($group2->id, ['priority' => 1]);
    $taxRate->taxGroups()->attach($group3->id, ['priority' => 2]);

    $groups = $taxRate->taxGroups;

    expect($groups[0]->pivot->priority)->toBe(1)
        ->and($groups[1]->pivot->priority)->toBe(2)
        ->and($groups[2]->pivot->priority)->toBe(3);
});

it('can create tax rate for different types', function () {
    $vat      = TaxRate::factory()->create(['type' => TaxType::VAT]);
    $salesTax = TaxRate::factory()->create(['type' => TaxType::SALES_TAX]);
    $gst      = TaxRate::factory()->create(['type' => TaxType::GST]);
    $other    = TaxRate::factory()->create(['type' => TaxType::OTHER]);

    expect($vat->type)->toBe(TaxType::VAT)
        ->and($salesTax->type)->toBe(TaxType::SALES_TAX)
        ->and($gst->type)->toBe(TaxType::GST)
        ->and($other->type)->toBe(TaxType::OTHER);
});

it('can create tax rate with regional variations', function () {
    $stateTax = TaxRate::factory()->create([
        'name'   => 'MA State Sales Tax',
        'rate'   => 625,
        'region' => 'US-MA',
        'type'   => TaxType::SALES_TAX,
    ]);

    expect($stateTax->name)->toBe('MA State Sales Tax')
        ->and($stateTax->rate)->toBe(625)
        ->and($stateTax->region)->toBe('US-MA');
});

it('can create tax rate with city level taxation', function () {
    $cityTax = TaxRate::factory()->create([
        'name'   => 'Boston City Surcharge',
        'rate'   => 50,
        'region' => 'US-MA-BOSTON',
        'type'   => TaxType::SALES_TAX,
    ]);

    expect($cityTax->name)->toBe('Boston City Surcharge')
        ->and($cityTax->rate)->toBe(50)
        ->and($cityTax->region)->toBe('US-MA-BOSTON');
});

it('stores rate as base-100 integer', function () {
    // 21% => 2100
    $taxRate = TaxRate::factory()->create(['rate' => 2100]);
    expect($taxRate->rate)->toBe(2100);

    // 19.5% => 1950
    $taxRate2 = TaxRate::factory()->create(['rate' => 1950]);
    expect($taxRate2->rate)->toBe(1950);
});

it('can update tax rate', function () {
    $taxRate = TaxRate::factory()->create([
        'rate' => 2100,
    ]);

    $taxRate->update(['rate' => 2150]);

    expect($taxRate->fresh()->rate)->toBe(2150);
});

it('can query tax rates by type', function () {
    TaxRate::factory()->create(['type' => TaxType::VAT]);
    TaxRate::factory()->create(['type' => TaxType::VAT]);
    TaxRate::factory()->create(['type' => TaxType::SALES_TAX]);

    $vatRates = TaxRate::where('type', TaxType::VAT)->get();

    expect($vatRates)->toHaveCount(2);
});

it('can query tax rates by region', function () {
    TaxRate::factory()->create(['region' => 'ES']);
    TaxRate::factory()->create(['region' => 'FR']);
    TaxRate::factory()->create(['region' => 'ES']);

    $esRates = TaxRate::where('region', 'ES')->get();

    expect($esRates)->toHaveCount(2);
});

it('has timestamps', function () {
    $taxRate = TaxRate::factory()->create();

    expect($taxRate->created_at)->toBeInstanceOf(\Carbon\Carbon::class)
        ->and($taxRate->updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

it('can detach from tax groups', function () {
    $taxRate  = TaxRate::factory()->create();
    $taxGroup = TaxGroup::factory()->create();

    $taxRate->taxGroups()->attach($taxGroup->id, ['priority' => 1]);
    expect($taxRate->taxGroups)->toHaveCount(1);

    $taxRate->taxGroups()->detach($taxGroup->id);
    expect($taxRate->fresh()->taxGroups)->toHaveCount(0);
});

it('can access pivot data', function () {
    $taxRate  = TaxRate::factory()->create();
    $taxGroup = TaxGroup::factory()->create();

    $taxRate->taxGroups()->attach($taxGroup->id, ['priority' => 5]);

    $firstGroup = $taxRate->taxGroups->first();
    expect($firstGroup->pivot->priority)->toBe(5);
});
