<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Commission;

it('can create a commission', function () {
    $commission = Commission::factory()->create([
        'name' => 'Test Commission',
        'level' => 'global',
        'rate' => 10.5,
    ]);

    expect($commission->name)->toBe('Test Commission')
        ->and($commission->level)->toBe('global')
        ->and($commission->rate)->toBe(10.5)
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
    Commission::factory()->create(['level' => 'global']);
    Commission::factory()->create(['level' => 'product']);

    $globalCommissions = Commission::forLevel('global')->get();

    expect($globalCommissions)->toHaveCount(1)
        ->and($globalCommissions->first()->level)->toBe('global');
});

it('can scope by type', function () {
    Commission::factory()->create(['type' => 'percentage']);
    Commission::factory()->create(['type' => 'fixed']);

    $percentageCommissions = Commission::forType('percentage')->get();

    expect($percentageCommissions)->toHaveCount(1)
        ->and($percentageCommissions->first()->type)->toBe('percentage');
});

it('can check if commission is currently valid', function () {
    $commission = Commission::factory()->create([
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);

    expect($commission->isCurrentlyValid())->toBeTrue();
});

it('detects expired commission', function () {
    $commission = Commission::factory()->create([
        'valid_from' => now()->subMonth(),
        'valid_until' => now()->subDay(),
    ]);

    expect($commission->isCurrentlyValid())->toBeFalse();
});

it('handles commission without end date as always valid', function () {
    $commission = Commission::factory()->create([
        'valid_from' => now()->subDay(),
        'valid_until' => null,
    ]);

    expect($commission->isCurrentlyValid())->toBeTrue();
});

it('supports multi-level commission structure', function () {
    $global = Commission::factory()->create(['level' => 'global', 'rate' => 5.0]);
    $group = Commission::factory()->create(['level' => 'product_group', 'rate' => 7.5]);
    $product = Commission::factory()->create(['level' => 'product', 'rate' => 10.0]);

    expect($global->level)->toBe('global')
        ->and($group->level)->toBe('product_group')
        ->and($product->level)->toBe('product')
        ->and($product->rate)->toBeGreaterThan($group->rate);
});

it('uses soft deletes', function () {
    $commission = Commission::factory()->create();
    $commissionId = $commission->id;

    $commission->delete();

    expect(Commission::find($commissionId))->toBeNull()
        ->and(Commission::withTrashed()->find($commissionId))->not->toBeNull();
});

