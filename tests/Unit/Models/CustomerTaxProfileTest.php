<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Customer, CustomerTaxProfile};

it('can create a customer tax profile', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'legal_name'   => 'John Doe',
        'tax_code'     => 'X1234567A',
        'country_code' => 'ES',
    ]);

    expect($profile->legal_name)->toBe('John Doe')
        ->and($profile->tax_code)->toBe('X1234567A')
        ->and($profile->country_code)->toBe('ES')
        ->and($profile->exists)->toBeTrue();
});

it('belongs to a customer', function () {
    $profile = CustomerTaxProfile::factory()->create();

    expect($profile->customer())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($profile->customer)->toBeInstanceOf(Customer::class);
});

it('can scope active profiles', function () {
    $customer = Customer::factory()->create();

    CustomerTaxProfile::factory()->create([
        'customer_id' => $customer->id,
        'is_current'  => false,
    ]);

    $currentProfiles = CustomerTaxProfile::where('customer_id', $customer->id)
        ->where('is_current', true)
        ->get();

    expect($currentProfiles)->toHaveCount(1)
        ->and($currentProfiles->first()->is_current)->toBeTrue();
});

it('stores complete fiscal address', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'address'      => 'Calle Test 123',
        'city'         => 'Madrid',
        'postal_code'  => '28001',
        'country_code' => 'ES',
    ]);

    expect($profile->address)->toBe('Calle Test 123')
        ->and($profile->city)->toBe('Madrid')
        ->and($profile->postal_code)->toBe('28001')
        ->and($profile->country_code)->toBe('ES');
});

it('has ROI verification fields', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'vat_number_verified' => true,
        'vat_verified_at'     => now(),
    ]);

    expect($profile->vat_number_verified)->toBeTrue()
        ->and($profile->vat_verified_at)->not->toBeNull();
});

it('casts metadata as array', function () {
    $profile = CustomerTaxProfile::factory()->create([
        'metadata' => ['custom_field' => 'value'],
    ]);

    expect($profile->metadata)->toBeArray()
        ->and($profile->metadata)->toHaveKey('custom_field');
});
