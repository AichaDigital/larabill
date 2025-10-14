<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\UnitMeasureCategory;
use AichaDigital\Larabill\Models\UnitMeasure;

it('can create a unit measure', function () {
    $unitMeasure = UnitMeasure::create([
        'name'          => 'Kilograms',
        'symbol'        => 'kg',
        'category'      => UnitMeasureCategory::WEIGHT,
        'is_fractional' => true,
        'is_active'     => true,
    ]);

    expect($unitMeasure->exists)->toBeTrue();
    expect($unitMeasure->name)->toBe('Kilograms');
    expect($unitMeasure->symbol)->toBe('kg');
    expect($unitMeasure->category)->toBe(UnitMeasureCategory::WEIGHT);
    expect($unitMeasure->is_fractional)->toBeTrue();
    expect($unitMeasure->is_active)->toBeTrue();
});

it('can scope active unit measures', function () {
    UnitMeasure::create([
        'name'      => 'Active Unit',
        'symbol'    => 'au',
        'category'  => UnitMeasureCategory::COUNT,
        'is_active' => true,
    ]);

    UnitMeasure::create([
        'name'      => 'Inactive Unit',
        'symbol'    => 'iu',
        'category'  => UnitMeasureCategory::COUNT,
        'is_active' => false,
    ]);

    $active = UnitMeasure::where('is_active', true)->get();
    expect($active)->toHaveCount(1);
    expect($active->first()->symbol)->toBe('au');
});

it('can filter by category', function () {
    UnitMeasure::create([
        'name'     => 'Units',
        'symbol'   => 'u.',
        'category' => UnitMeasureCategory::COUNT,
    ]);

    UnitMeasure::create([
        'name'     => 'Kilograms',
        'symbol'   => 'kg',
        'category' => UnitMeasureCategory::WEIGHT,
    ]);

    UnitMeasure::create([
        'name'     => 'Liters',
        'symbol'   => 'L',
        'category' => UnitMeasureCategory::VOLUME,
    ]);

    $weightUnits = UnitMeasure::where('category', UnitMeasureCategory::WEIGHT)->get();
    expect($weightUnits)->toHaveCount(1);
    expect($weightUnits->first()->symbol)->toBe('kg');
});

it('can handle fractional and non-fractional units', function () {
    UnitMeasure::create([
        'name'          => 'Units',
        'symbol'        => 'u.',
        'category'      => UnitMeasureCategory::COUNT,
        'is_fractional' => false,
    ]);

    UnitMeasure::create([
        'name'          => 'Kilograms',
        'symbol'        => 'kg',
        'category'      => UnitMeasureCategory::WEIGHT,
        'is_fractional' => true,
    ]);

    $fractional = UnitMeasure::where('is_fractional', true)->get();
    $nonFractional = UnitMeasure::where('is_fractional', false)->get();

    expect($fractional)->toHaveCount(1);
    expect($fractional->first()->symbol)->toBe('kg');
    expect($nonFractional)->toHaveCount(1);
    expect($nonFractional->first()->symbol)->toBe('u.');
});

it('can store all unit measure categories', function () {
    $units = [
        ['name' => 'Units', 'symbol' => 'u.', 'category' => UnitMeasureCategory::COUNT, 'is_fractional' => false],
        ['name' => 'Kilograms', 'symbol' => 'kg', 'category' => UnitMeasureCategory::WEIGHT, 'is_fractional' => true],
        ['name' => 'Liters', 'symbol' => 'L', 'category' => UnitMeasureCategory::VOLUME, 'is_fractional' => true],
        ['name' => 'Meters', 'symbol' => 'm', 'category' => UnitMeasureCategory::LENGTH, 'is_fractional' => true],
        ['name' => 'Hours', 'symbol' => 'hr', 'category' => UnitMeasureCategory::TIME, 'is_fractional' => true],
        ['name' => 'Square Meters', 'symbol' => 'm²', 'category' => UnitMeasureCategory::AREA, 'is_fractional' => true],
        ['name' => 'Services', 'symbol' => 'svc', 'category' => UnitMeasureCategory::OTHER, 'is_fractional' => false],
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
    expect(UnitMeasure::where('category', UnitMeasureCategory::OTHER)->count())->toBe(1);
});

it('can get units by category and active status', function () {
    UnitMeasure::create([
        'name'      => 'Kilograms',
        'symbol'    => 'kg',
        'category'  => UnitMeasureCategory::WEIGHT,
        'is_active' => true,
    ]);

    UnitMeasure::create([
        'name'      => 'Pounds',
        'symbol'    => 'lb',
        'category'  => UnitMeasureCategory::WEIGHT,
        'is_active' => false,
    ]);

    $activeWeightUnits = UnitMeasure::where('category', UnitMeasureCategory::WEIGHT)
        ->where('is_active', true)
        ->get();

    expect($activeWeightUnits)->toHaveCount(1);
    expect($activeWeightUnits->first()->symbol)->toBe('kg');
});

it('enforces unique name per category', function () {
    UnitMeasure::create([
        'name'     => 'Units',
        'symbol'   => 'u.',
        'category' => UnitMeasureCategory::COUNT,
    ]);

    // This should fail due to unique constraint on (name, category)
    expect(function () {
        UnitMeasure::create([
            'name'     => 'Units',
            'symbol'   => 'u2',
            'category' => UnitMeasureCategory::COUNT,
        ]);
    })->toThrow(\Exception::class);
});

it('allows same name in different categories', function () {
    UnitMeasure::create([
        'name'     => 'Standard',
        'symbol'   => 'std',
        'category' => UnitMeasureCategory::COUNT,
    ]);

    UnitMeasure::create([
        'name'     => 'Standard',
        'symbol'   => 'std2',
        'category' => UnitMeasureCategory::WEIGHT,
    ]);

    $standards = UnitMeasure::where('name', 'Standard')->get();
    expect($standards)->toHaveCount(2);
});

