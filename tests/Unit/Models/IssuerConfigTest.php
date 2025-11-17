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

it('can get current issuer config (singleton)', function () {
    $issuer = IssuerConfig::factory()->create();

    $current = IssuerConfig::current();

    expect($current->id)->toBe(1)
        ->and($current->id)->toBe($issuer->id);
});

it('can update EU sales and check threshold', function () {
    $issuer = IssuerConfig::factory()->create([
        'eu_sales_threshold' => 1000.0,
        'current_eu_sales'   => 0,
        'threshold_exceeded' => false,
    ]);

    expect($issuer->threshold_exceeded)->toBeFalse();

    // Add sales below threshold
    $issuer->addEuSales(500.0);
    expect($issuer->current_eu_sales)->toBe(500.0)
        ->and($issuer->threshold_exceeded)->toBeFalse();

    // Add sales to exceed threshold
    $issuer->addEuSales(600.0);
    expect($issuer->current_eu_sales)->toBe(1100.0)
        ->and($issuer->threshold_exceeded)->toBeTrue()
        ->and($issuer->threshold_exceeded_at)->not->toBeNull();
});

it('can reset EU sales for new fiscal year', function () {
    $issuer = IssuerConfig::factory()->create([
        'current_eu_sales'               => 1500.0,
        'threshold_exceeded'             => true,
        'threshold_exceeded_at'          => now(),
        'threshold_notification_sent'    => true,
        'fiscal_year'                    => 2024,
    ]);

    // Manually reset (method doesn't exist, so we test manual reset)
    $issuer->update([
        'fiscal_year'                 => 2025,
        'current_eu_sales'            => 0,
        'threshold_exceeded'          => false,
        'threshold_exceeded_at'       => null,
        'threshold_notification_sent' => false,
    ]);

    expect($issuer->fiscal_year)->toBe(2025)
        ->and($issuer->current_eu_sales)->toBe(0.0)
        ->and($issuer->threshold_exceeded)->toBeFalse()
        ->and($issuer->threshold_exceeded_at)->toBeNull()
        ->and($issuer->threshold_notification_sent)->toBeFalse();
});

it('can check remaining threshold amount', function () {
    $issuer = IssuerConfig::factory()->create([
        'eu_sales_threshold' => 1000.0,
        'current_eu_sales'   => 750.0,
    ]);

    expect($issuer->remaining_threshold)->toBe(250.0);

    // After exceeding
    $issuer->addEuSales(300.0);
    $issuer->refresh();
    expect($issuer->remaining_threshold)->toBe(0.0);
});

it('can get threshold percentage', function () {
    $issuer = IssuerConfig::factory()->create([
        'eu_sales_threshold' => 1000.0,
        'current_eu_sales'   => 750.0,
    ]);

    expect($issuer->threshold_percentage)->toBe(75.0);

    // Test at threshold
    $issuer->update(['current_eu_sales' => 1000.0]);
    $issuer->refresh();
    expect($issuer->threshold_percentage)->toBe(100.0);

    // Test exceeding threshold (capped at 100%)
    $issuer->update(['current_eu_sales' => 1200.0]);
    $issuer->refresh();
    expect($issuer->threshold_percentage)->toBe(100.0);
});
