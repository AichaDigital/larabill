<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\IssuerConfig;

it('can create issuer config', function () {
    $issuer = IssuerConfig::factory()->create([
        'company_name' => 'Test Company SL',
    ]);

    expect($issuer->company_name)->toBe('Test Company SL')
        ->and($issuer->exists)->toBeTrue();
});

it('has relationship with current profile', function () {
    $issuer = IssuerConfig::factory()->create();

    expect($issuer->currentProfile())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
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

it('stores company information correctly', function () {
    $issuer = IssuerConfig::factory()->create([
        'company_name' => 'AichaDigital SL',
        'trade_name'   => 'Aicha Digital',
    ]);

    expect($issuer->company_name)->toBe('AichaDigital SL')
        ->and($issuer->trade_name)->toBe('Aicha Digital');
});
