<?php

declare(strict_types=1);

/**
 * @deprecated v0.4.0 - Tests use legacy API (category column, code as PK)
 * @see CustomerTest for updated tests using v0.4.0 architecture
 *
 * Several tests use columns that don't exist in v0.4.0:
 * - category (removed)
 * - requires_commercial_register (removed)
 * - Primary key is 'id' not 'code'
 */

use AichaDigital\Larabill\Models\LegalEntityType;

// Skip legacy tests that use removed columns
beforeEach(function () {
    if (in_array($this->name(), [
        'it can create a legal entity type',
        'it can scope by category',
        'it stores fiscal requirements',
        'it uses code as primary key',
    ])) {
        $this->markTestSkipped('Legacy test uses removed columns/API');
    }
});

it('can create a legal entity type', function () {
    $type = LegalEntityType::factory()->create([
        'code'     => 'TEST_TYPE',
        'name'     => 'Test Legal Entity',
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
        'requires_tax_id'              => true,
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
