<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\UnitMeasureCategory;
use AichaDigital\Larabill\Models\UnitMeasure;

it('can create a unit measure', function () {
    $unitMeasure = UnitMeasure::create([
        'code'     => 'kg',
        'symbol'   => 'kg',
        'name'     => 'Kilograms',
        'category' => UnitMeasureCategory::WEIGHT,
        'is_active' => true,
    ]);

    expect($unitMeasure->exists)->toBeTrue();
    expect($unitMeasure->code)->toBe('kg');
    expect($unitMeasure->symbol)->toBe('kg');
    expect($unitMeasure->name)->toBe('Kilograms');
    expect($unitMeasure->category)->toBe(UnitMeasureCategory::WEIGHT);
    expect($unitMeasure->is_active)->toBeTrue();
});

it('can scope active unit measures', function () {
    UnitMeasure::create([
        'code'      => 'au',
        'symbol'    => 'au',
        'name'      => 'Active Unit',
        'category'  => UnitMeasureCategory::COUNT,
        'is_active' => true,
    ]);

    UnitMeasure::create([
        'code'      => 'iu',
        'symbol'    => 'iu',
        'name'      => 'Inactive Unit',
        'category'  => UnitMeasureCategory::COUNT,
        'is_active' => false,
    ]);

    $active = UnitMeasure::where('is_active', true)->get();
    expect($active)->toHaveCount(1);
    expect($active->first()->code)->toBe('au');
});

it('can filter by category', function () {
    UnitMeasure::create([
        'code'     => 'unit',
        'symbol'   => 'u.',
        'name'     => 'Units',
        'category' => UnitMeasureCategory::COUNT,
    ]);

    UnitMeasure::create([
        'code'     => 'kg',
        'symbol'   => 'kg',
        'name'     => 'Kilograms',
        'category' => UnitMeasureCategory::WEIGHT,
    ]);

    UnitMeasure::create([
        'code'     => 'liter',
        'symbol'   => 'L',
        'name'     => 'Liters',
        'category' => UnitMeasureCategory::VOLUME,
    ]);

    $weightUnits = UnitMeasure::where('category', UnitMeasureCategory::WEIGHT)->get();
    expect($weightUnits)->toHaveCount(1);
    expect($weightUnits->first()->code)->toBe('kg');
});

it('can store all unit measure categories', function () {
    $units = [
        ['code' => 'unit', 'symbol' => 'u.', 'name' => 'Units', 'category' => UnitMeasureCategory::COUNT],
        ['code' => 'kg', 'symbol' => 'kg', 'name' => 'Kilograms', 'category' => UnitMeasureCategory::WEIGHT],
        ['code' => 'liter', 'symbol' => 'L', 'name' => 'Liters', 'category' => UnitMeasureCategory::VOLUME],
        ['code' => 'meter', 'symbol' => 'm', 'name' => 'Meters', 'category' => UnitMeasureCategory::LENGTH],
        ['code' => 'hour', 'symbol' => 'hr', 'name' => 'Hours', 'category' => UnitMeasureCategory::TIME],
        ['code' => 'm2', 'symbol' => 'm²', 'name' => 'Square Meters', 'category' => UnitMeasureCategory::AREA],
    ];

    foreach ($units as $unit) {
        UnitMeasure::create($unit);
    }

    expect(UnitMeasure::where('category', UnitMeasureCategory::COUNT)->count())->toBe(1);
    expect(UnitMeasure::where('category', UnitMeasureCategory::WEIGHT)->count())->toBe(1);
    expect(UnitMeasure::where('category', UnitMeasureCategory::VOLUME)->count())->toBe(1);
    expect(UnitMeasure::where('category', UnitMeasureCategory::LENGTH)->count())->toBe(1);
    expect(UnitMeasure::where('category', UnitMeasureCategory::TIME)->count())->toBe(1);
    expect(UnitMeasure::where('category', UnitMeasureCategory::AREA)->count())->toBe(1);
});

it('can get units by category and active status', function () {
    UnitMeasure::create([
        'code'      => 'kg',
        'symbol'    => 'kg',
        'name'      => 'Kilograms',
        'category'  => UnitMeasureCategory::WEIGHT,
        'is_active' => true,
    ]);

    UnitMeasure::create([
        'code'      => 'lb',
        'symbol'    => 'lb',
        'name'      => 'Pounds',
        'category'  => UnitMeasureCategory::WEIGHT,
        'is_active' => false,
    ]);

    $activeWeightUnits = UnitMeasure::where('category', UnitMeasureCategory::WEIGHT)
        ->where('is_active', true)
        ->get();

    expect($activeWeightUnits)->toHaveCount(1);
    expect($activeWeightUnits->first()->code)->toBe('kg');
});

it('enforces unique code constraint', function () {
    UnitMeasure::create([
        'code'   => 'unit',
        'symbol' => 'u.',
        'name'   => 'Units',
        'category' => UnitMeasureCategory::COUNT,
    ]);

    // This should fail due to unique constraint on code
    expect(function () {
        UnitMeasure::create([
            'code'   => 'unit',
            'symbol' => 'u2',
            'name'   => 'Units 2',
            'category' => UnitMeasureCategory::COUNT,
        ]);
    })->toThrow(\Exception::class);
});

it('can order by sort_order', function () {
    UnitMeasure::create(['code' => 'c', 'symbol' => 'c', 'name' => 'Third', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 3]);
    UnitMeasure::create(['code' => 'a', 'symbol' => 'a', 'name' => 'First', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 1]);
    UnitMeasure::create(['code' => 'b', 'symbol' => 'b', 'name' => 'Second', 'category' => UnitMeasureCategory::COUNT, 'sort_order' => 2]);

    $ordered = UnitMeasure::orderBy('sort_order')->get();
    expect($ordered->first()->code)->toBe('a');
    expect($ordered->last()->code)->toBe('c');
});
