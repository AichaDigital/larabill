<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use Carbon\Carbon;

beforeEach(function () {
    // Limpiar configs previas
    CompanyFiscalConfig::query()->delete();
});

it('can create a company fiscal config', function () {
    $config = CompanyFiscalConfig::factory()->create();

    expect($config)->toBeInstanceOf(CompanyFiscalConfig::class)
        ->and($config->business_name)->toBeString()
        ->and($config->tax_id)->toBeString()
        ->and($config->is_active)->toBeTrue()
        ->and($config->valid_until)->toBeNull();
});

it('auto-closes previous config when creating new one', function () {
    // Config inicial (activa)
    $firstConfig = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => null,
        'is_active'   => true,
    ]);

    expect($firstConfig->fresh()->is_active)->toBeTrue()
        ->and($firstConfig->fresh()->valid_until)->toBeNull();

    // Nueva config (debe cerrar la anterior)
    $secondConfig = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2024-01-01'),
        'valid_until' => null,
        'is_active'   => true,
    ]);

    // Primera config debe estar cerrada
    expect($firstConfig->fresh()->is_active)->toBeFalse()
        ->and($firstConfig->fresh()->valid_until)->toEqual(Carbon::parse('2023-12-31'));

    // Segunda config debe estar activa
    expect($secondConfig->fresh()->is_active)->toBeTrue()
        ->and($secondConfig->fresh()->valid_until)->toBeNull();
});

it('retrieves active config correctly', function () {
    CompanyFiscalConfig::factory()->historical()->create();
    $active = CompanyFiscalConfig::factory()->active()->create();

    $retrieved = CompanyFiscalConfig::getActive();

    expect($retrieved->id)->toBe($active->id)
        ->and($retrieved->is_active)->toBeTrue()
        ->and($retrieved->valid_until)->toBeNull();
});

it('retrieves config valid at specific date', function () {
    // Config 2023
    $config2023 = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => Carbon::parse('2023-12-31'),
        'is_active'   => false,
    ]);

    // Config 2024 (activa)
    $config2024 = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2024-01-01'),
        'valid_until' => null,
        'is_active'   => true,
    ]);

    // Query fecha en 2023
    $validIn2023 = CompanyFiscalConfig::getValidAt(Carbon::parse('2023-06-15'));
    expect($validIn2023->id)->toBe($config2023->id);

    // Query fecha en 2024
    $validIn2024 = CompanyFiscalConfig::getValidAt(Carbon::parse('2024-06-15'));
    expect($validIn2024->id)->toBe($config2024->id);
});

it('checks if config is currently active', function () {
    $active     = CompanyFiscalConfig::factory()->active()->create();
    $historical = CompanyFiscalConfig::factory()->historical()->create();

    expect($active->isCurrentlyActive())->toBeTrue()
        ->and($historical->isCurrentlyActive())->toBeFalse();
});

it('checks if config was valid at date', function () {
    $config = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => Carbon::parse('2023-12-31'),
    ]);

    expect($config->wasValidAt(Carbon::parse('2023-06-15')))->toBeTrue()
        ->and($config->wasValidAt(Carbon::parse('2024-06-15')))->toBeFalse()
        ->and($config->wasValidAt(Carbon::parse('2022-06-15')))->toBeFalse();
});

it('formats validity range correctly', function () {
    $active = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2024-01-01'),
        'valid_until' => null,
    ]);

    $historical = CompanyFiscalConfig::factory()->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => Carbon::parse('2023-12-31'),
    ]);

    expect($active->validity_range)->toContain('Actual')
        ->and($historical->validity_range)->toContain('31/12/2023');
});

it('formats full fiscal identity correctly', function () {
    $config = CompanyFiscalConfig::factory()->create([
        'business_name' => 'Test Company S.L.',
        'tax_id'        => 'ESB12345678',
    ]);

    expect($config->full_fiscal_identity)
        ->toBe('Test Company S.L. (ESB12345678)');
});

it('formats full address correctly', function () {
    $config = CompanyFiscalConfig::factory()->create([
        'address'      => 'Calle Test 123',
        'zip_code'     => '28001',
        'city'         => 'Madrid',
        'state'        => 'Madrid',
        'country_code' => 'ES',
    ]);

    expect($config->full_address)->toContain('Calle Test 123')
        ->and($config->full_address)->toContain('28001')
        ->and($config->full_address)->toContain('Madrid');
});

it('creates OSS operator config', function () {
    $config = CompanyFiscalConfig::factory()->oss()->create();

    expect($config->is_oss)->toBeTrue();
});

it('creates ROI operator config', function () {
    $config = CompanyFiscalConfig::factory()->roi()->create();

    expect($config->is_roi)->toBeTrue();
});

it('scopes configs by country', function () {
    CompanyFiscalConfig::factory()->spanish()->create();
    CompanyFiscalConfig::factory()->french()->create();

    $spanish = CompanyFiscalConfig::country('ES')->get();
    $french  = CompanyFiscalConfig::country('FR')->get();

    expect($spanish)->toHaveCount(1)
        ->and($french)->toHaveCount(1);
});

it('scopes OSS operators', function () {
    CompanyFiscalConfig::factory()->oss()->create();
    CompanyFiscalConfig::factory()->create(['is_oss' => false]);

    $oss = CompanyFiscalConfig::oss()->get();

    expect($oss)->toHaveCount(1)
        ->and($oss->first()->is_oss)->toBeTrue();
});

it('scopes ROI operators', function () {
    CompanyFiscalConfig::factory()->roi()->create();
    CompanyFiscalConfig::factory()->create(['is_roi' => false]);

    $roi = CompanyFiscalConfig::roi()->get();

    expect($roi)->toHaveCount(1)
        ->and($roi->first()->is_roi)->toBeTrue();
});
