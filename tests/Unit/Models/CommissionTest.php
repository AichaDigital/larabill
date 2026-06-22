<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CommissionLevel;
use AichaDigital\Larabill\Enums\CommissionType;
use AichaDigital\Larabill\Models\Commission;

it('can create a commission', function () {
    $commission = Commission::factory()->create([
        'name'  => 'Test Commission',
        'level' => CommissionLevel::GLOBAL,
        'rate'  => cents(1050), // 10.50% in base100
    ]);

    expect($commission->name)->toBe('Test Commission')
        ->and($commission->level)->toBe(CommissionLevel::GLOBAL)
        ->and($commission->rate->unscaledValue())->toBe(1050) // 10.50% in base100
        ->and($commission->exists)->toBeTrue();
});

it('can scope active commissions', function () {
    Commission::factory()->create(['is_active' => true]);
    Commission::factory()->create(['is_active' => false]);

    $activeCommissions = Commission::active()->get();

    expect($activeCommissions)->toHaveCount(1)
        ->and($activeCommissions->first()->is_active)->toBeTrue();
});

it('can scope by level', function () {
    Commission::factory()->create(['level' => CommissionLevel::GLOBAL]);
    Commission::factory()->create(['level' => CommissionLevel::PRODUCT]);

    $globalCommissions = Commission::forLevel(CommissionLevel::GLOBAL)->get();

    expect($globalCommissions)->toHaveCount(1)
        ->and($globalCommissions->first()->level)->toBe(CommissionLevel::GLOBAL);
});

it('can scope by type', function () {
    Commission::factory()->create(['type' => CommissionType::PERCENTAGE]);
    Commission::factory()->create(['type' => CommissionType::FIXED]);

    $percentageCommissions = Commission::forType(CommissionType::PERCENTAGE)->get();

    expect($percentageCommissions)->toHaveCount(1)
        ->and($percentageCommissions->first()->type)->toBe(CommissionType::PERCENTAGE);
});

it('can check if commission is currently valid', function () {
    $commission = Commission::factory()->create([
        'valid_from'  => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);

    expect($commission->isCurrentlyValid())->toBeTrue();
});

it('detects expired commission', function () {
    $commission = Commission::factory()->create([
        'valid_from'  => now()->subMonth(),
        'valid_until' => now()->subDay(),
    ]);

    expect($commission->isCurrentlyValid())->toBeFalse();
});

it('handles commission without end date as always valid', function () {
    $commission = Commission::factory()->create([
        'valid_from'  => now()->subDay(),
        'valid_until' => null,
    ]);

    expect($commission->isCurrentlyValid())->toBeTrue();
});

it('supports multi-level commission structure', function () {
    $global  = Commission::factory()->create(['level' => CommissionLevel::GLOBAL, 'rate' => cents((int) 5.0)]);
    $group   = Commission::factory()->create(['level' => CommissionLevel::PRODUCT_GROUP, 'rate' => cents((int) 7.5)]);
    $product = Commission::factory()->create(['level' => CommissionLevel::PRODUCT, 'rate' => cents((int) 10.0)]);

    expect($global->level)->toBe(CommissionLevel::GLOBAL)
        ->and($group->level)->toBe(CommissionLevel::PRODUCT_GROUP)
        ->and($product->level)->toBe(CommissionLevel::PRODUCT)
        ->and($product->rate->isGreaterThan($group->rate))->toBeTrue();
});

it('uses soft deletes', function () {
    $commission   = Commission::factory()->create();
    $commissionId = $commission->id;

    $commission->delete();

    expect(Commission::find($commissionId))->toBeNull()
        ->and(Commission::withTrashed()->find($commissionId))->not->toBeNull();
});
