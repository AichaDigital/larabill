<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\UnitMeasureCategory;
use AichaDigital\Larabill\Models\UnitMeasure;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('can create a unit measure', function () {
    $unitMeasure = UnitMeasure::factory()->create([
        'code'     => 'kg',
        'category' => UnitMeasureCategory::WEIGHT,
    ]);

    expect($unitMeasure->exists)->toBeTrue();
    expect($unitMeasure->code)->toBe('kg');
    expect($unitMeasure->category)->toBe(UnitMeasureCategory::WEIGHT);
});

it('can scope active unit measures', function () {
    UnitMeasure::factory()->create(['code' => 'au', 'is_active' => true]);
    UnitMeasure::factory()->create(['code' => 'iu', 'is_active' => false]);

    $active = UnitMeasure::where('is_active', true)->get();
    expect($active)->toHaveCount(1);
    expect($active->first()->code)->toBe('au');
});

it('can filter by category', function () {
    UnitMeasure::factory()->create(['category' => UnitMeasureCategory::COUNT]);
    UnitMeasure::factory()->create(['code' => 'kg', 'category' => UnitMeasureCategory::WEIGHT]);
    UnitMeasure::factory()->create(['category' => UnitMeasureCategory::VOLUME]);

    $weightUnits = UnitMeasure::where('category', UnitMeasureCategory::WEIGHT)->get();
    expect($weightUnits)->toHaveCount(1);
    expect($weightUnits->first()->code)->toBe('kg');
});

it('enforces unique code constraint', function () {
    UnitMeasure::factory()->create(['code' => 'unit']);

    // This should fail due to unique constraint on code
    expect(fn () => UnitMeasure::factory()->create(['code' => 'unit']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('can order by sort_order', function () {
    UnitMeasure::factory()->create(['code' => 'c', 'sort_order' => 3]);
    UnitMeasure::factory()->create(['code' => 'a', 'sort_order' => 1]);
    UnitMeasure::factory()->create(['code' => 'b', 'sort_order' => 2]);

    $ordered = UnitMeasure::orderBy('sort_order')->get();
    expect($ordered->first()->code)->toBe('a');
    expect($ordered->last()->code)->toBe('c');
});
