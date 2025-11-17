<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Customer, CustomerTaxProfile};

it('can create a customer tax profile', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'legal_name'   => 'John Doe',
        'tax_id'       => 'X1234567A',
        'country_code' => 'ES',
    ]);

    expect($profile->legal_name)->toBe('John Doe')
        ->and($profile->tax_id)->toBe('X1234567A')
        ->and($profile->country_code)->toBe('ES')
        ->and($profile->exists)->toBeTrue();
});

it('belongs to a customer', function () {
    $profile = CustomerTaxProfile::factory()->create();

    expect($profile->customer())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($profile->customer)->toBeInstanceOf(Customer::class);
});

it('can scope active profiles', function () {
    CustomerTaxProfile::factory()->create(['is_active' => true]);
    CustomerTaxProfile::factory()->create(['is_active' => false]);

    $activeProfiles = CustomerTaxProfile::active()->get();

    expect($activeProfiles)->toHaveCount(1)
        ->and($activeProfiles->first()->is_active)->toBeTrue();
});

it('stores complete fiscal address', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'fiscal_address'     => 'Calle Test 123',
        'fiscal_city'        => 'Madrid',
        'fiscal_postal_code' => '28001',
        'fiscal_country'     => 'ES',
    ]);

    expect($profile->fiscal_address)->toBe('Calle Test 123')
        ->and($profile->fiscal_city)->toBe('Madrid')
        ->and($profile->fiscal_postal_code)->toBe('28001')
        ->and($profile->fiscal_country)->toBe('ES');
});

it('has ROI verification fields', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'is_roi_verified' => true,
        'roi_verified_at' => now(),
    ]);

    expect($profile->is_roi_verified)->toBeTrue()
        ->and($profile->roi_verified_at)->not->toBeNull();
});

it('casts metadata as array', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'metadata' => ['custom_field' => 'value'],
    ]);

    expect($profile->metadata)->toBeArray()
        ->and($profile->metadata)->toHaveKey('custom_field');
});
