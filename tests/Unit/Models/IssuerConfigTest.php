<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{IssuerConfig, IssuerTaxProfile};

beforeEach(function () {
    // Ensure no issuer exists before each test (singleton)
    IssuerConfig::query()->delete();
});

it('can create issuer config', function () {
    $issuer = IssuerConfig::factory()->create();

    expect($issuer->exists)->toBeTrue()
        ->and($issuer->id)->toBe(1) // Singleton
        ->and($issuer->fiscal_year)->toBeInt();
});

it('has relationship with current profile', function () {
    $profile = IssuerTaxProfile::factory()->create();
    $issuer  = IssuerConfig::factory()->create([
        'current_tax_profile_id' => $profile->id,
    ]);

    expect($issuer->currentTaxProfile())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class)
        ->and($issuer->currentTaxProfile)->toBeInstanceOf(IssuerTaxProfile::class)
        ->and($issuer->currentTaxProfile->id)->toBe($profile->id);
});

it('has relationship with tax profiles', function () {
    $issuer = IssuerConfig::factory()->create();

    expect($issuer->taxProfiles())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

it('casts metadata as array', function () {
    $issuer = IssuerConfig::factory()->create([
        'metadata' => ['fiscal_regime' => 'general', 'tax_id' => 'B12345678'],
    ]);

    expect($issuer->metadata)->toBeArray()
        ->and($issuer->metadata)->toHaveKey('fiscal_regime')
        ->and($issuer->metadata['fiscal_regime'])->toBe('general');
});

it('stores fiscal information correctly', function () {
    $profile = IssuerTaxProfile::factory()->create([
        'legal_name'      => 'AichaDigital SL',
        'commercial_name' => 'Aicha Digital',
    ]);

    $issuer = IssuerConfig::factory()->create([
        'current_tax_profile_id' => $profile->id,
    ]);

    $issuer->load('currentTaxProfile');

    expect($issuer->currentTaxProfile->legal_name)->toBe('AichaDigital SL')
        ->and($issuer->currentTaxProfile->commercial_name)->toBe('Aicha Digital');
});
