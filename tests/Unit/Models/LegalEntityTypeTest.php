<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\LegalEntityType;

it('can create a legal entity type', function () {
    $type = LegalEntityType::factory()->create([
        'code' => 'TEST_TYPE',
        'name' => 'Test Legal Entity',
        'category' => 'person',
    ]);

    expect($type->code)->toBe('TEST_TYPE')
        ->and($type->name)->toBe('Test Legal Entity')
        ->and($type->category)->toBe('person')
        ->and($type->exists)->toBeTrue();
});

it('can scope by category', function () {
    LegalEntityType::factory()->create(['category' => 'person']);
    LegalEntityType::factory()->create(['category' => 'company']);

    $personTypes = LegalEntityType::category('person')->get();

    expect($personTypes)->toHaveCount(1)
        ->and($personTypes->first()->category)->toBe('person');
});

it('can scope active types', function () {
    LegalEntityType::factory()->create(['is_active' => true]);
    LegalEntityType::factory()->create(['is_active' => false]);

    $activeTypes = LegalEntityType::active()->get();

    expect($activeTypes)->toHaveCount(1)
        ->and($activeTypes->first()->is_active)->toBeTrue();
});

it('stores fiscal requirements', function () {
    $type = LegalEntityType::factory()->create([
        'requires_tax_id' => true,
        'requires_commercial_register' => true,
    ]);

    expect($type->requires_tax_id)->toBeTrue()
        ->and($type->requires_commercial_register)->toBeTrue();
});

it('has relationship with customers', function () {
    $type = LegalEntityType::factory()->create();

    expect($type->customers())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

it('uses code as primary key', function () {
    $type = LegalEntityType::factory()->create(['code' => 'CUSTOM_CODE']);

    expect($type->getKeyName())->toBe('code')
        ->and($type->getKey())->toBe('CUSTOM_CODE')
        ->and($type->incrementing)->toBeFalse();
});

