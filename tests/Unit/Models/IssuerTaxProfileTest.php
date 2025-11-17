<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{IssuerConfig, IssuerTaxProfile};

beforeEach(function () {
    // Clean singleton IssuerConfig
    IssuerConfig::query()->delete();
});

it('can create an issuer tax profile', function () {
    $profile = IssuerTaxProfile::factory()->create([
        'legal_name'   => 'AichaDigital SL',
        'tax_id'       => 'B12345678',
        'country_code' => 'ES',
    ]);

    expect($profile->legal_name)->toBe('AichaDigital SL')
        ->and($profile->tax_id)->toBe('B12345678')
        ->and($profile->country_code)->toBe('ES')
        ->and($profile->exists)->toBeTrue();
});

it('belongs to issuer config', function () {
    $issuer = IssuerConfig::factory()->create();
    $profile = IssuerTaxProfile::factory()->create([
        'issuer_config_id' => $issuer->id,
    ]);

    expect($profile->issuer())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($profile->issuer)->toBeInstanceOf(IssuerConfig::class);
});

it('can scope active profiles', function () {
    IssuerTaxProfile::factory()->create(['is_current' => true]);
    IssuerTaxProfile::factory()->create(['is_current' => false]);

    $currentProfiles = IssuerTaxProfile::where('is_current', true)->get();

    expect($currentProfiles)->toHaveCount(1)
        ->and($currentProfiles->first()->is_current)->toBeTrue();
});

it('stores complete fiscal information', function () {
    $profile = IssuerTaxProfile::factory()->create([
        'legal_name'     => 'AichaDigital SL',
        'commercial_name' => 'Aicha Digital',
        'tax_id'         => 'B12345678',
        'address'        => 'Calle Principal 1',
        'city'           => 'Barcelona',
        'postal_code'    => '08001',
        'country_code'   => 'ES',
    ]);

    expect($profile->legal_name)->toBe('AichaDigital SL')
        ->and($profile->commercial_name)->toBe('Aicha Digital')
        ->and($profile->tax_id)->toBe('B12345678')
        ->and($profile->address)->toBe('Calle Principal 1')
        ->and($profile->city)->toBe('Barcelona');
});

it('tracks profile validity period', function () {
    $profile = IssuerTaxProfile::factory()->create([
        'valid_from'  => now()->subMonth(),
        'valid_until' => now()->addMonth(),
    ]);

    expect($profile->valid_from)->not->toBeNull()
        ->and($profile->valid_until)->not->toBeNull()
        ->and($profile->valid_from)->toBeLessThan($profile->valid_until);
});

it('casts metadata as array', function () {
    $profile = IssuerTaxProfile::factory()->create([
        'metadata' => ['register' => 'RM123456'],
    ]);

    expect($profile->metadata)->toBeArray()
        ->and($profile->metadata)->toHaveKey('register');
});
