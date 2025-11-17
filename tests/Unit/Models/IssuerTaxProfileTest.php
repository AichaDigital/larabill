<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{IssuerConfig, IssuerTaxProfile};

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
    $profile = IssuerTaxProfile::factory()->create();

    expect($profile->issuerConfig())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($profile->issuerConfig)->toBeInstanceOf(IssuerConfig::class);
});

it('can scope active profiles', function () {
    IssuerTaxProfile::factory()->create(['is_active' => true]);
    IssuerTaxProfile::factory()->create(['is_active' => false]);

    $activeProfiles = IssuerTaxProfile::active()->get();

    expect($activeProfiles)->toHaveCount(1)
        ->and($activeProfiles->first()->is_active)->toBeTrue();
});

it('stores complete fiscal information', function () {
    $profile = IssuerTaxProfile::factory()->create([
        'legal_name'         => 'AichaDigital SL',
        'trade_name'         => 'Aicha Digital',
        'tax_id'             => 'B12345678',
        'fiscal_address'     => 'Calle Principal 1',
        'fiscal_city'        => 'Barcelona',
        'fiscal_postal_code' => '08001',
        'fiscal_country'     => 'ES',
    ]);

    expect($profile->legal_name)->toBe('AichaDigital SL')
        ->and($profile->trade_name)->toBe('Aicha Digital')
        ->and($profile->tax_id)->toBe('B12345678')
        ->and($profile->fiscal_address)->toBe('Calle Principal 1')
        ->and($profile->fiscal_city)->toBe('Barcelona');
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
