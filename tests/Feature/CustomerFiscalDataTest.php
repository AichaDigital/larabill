<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CustomerFiscalData;
use AichaDigital\Larabill\Tests\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    // Limpiar data previa
    CustomerFiscalData::query()->delete();
});

it('can create customer fiscal data', function () {
    $user = User::factory()->create();

    $data = CustomerFiscalData::factory()->forUser($user->id)->create();

    expect($data)->toBeInstanceOf(CustomerFiscalData::class)
        ->and($data->user_id)->toEqual((string) $user->id)
        ->and($data->fiscal_name)->toBeString()
        ->and($data->is_active)->toBeTrue()
        ->and($data->valid_until)->toBeNull();
});

it('auto-closes previous user config when creating new one', function () {
    $user = User::factory()->create();

    // Config inicial (activa)
    $firstData = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => null,
        'is_active'   => true,
    ]);

    expect($firstData->fresh()->is_active)->toBeTrue()
        ->and($firstData->fresh()->valid_until)->toBeNull();

    // Nueva config (debe cerrar la anterior)
    $secondData = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2024-01-01'),
        'valid_until' => null,
        'is_active'   => true,
    ]);

    // Primera config debe estar cerrada
    expect($firstData->fresh()->is_active)->toBeFalse()
        ->and($firstData->fresh()->valid_until)->toEqual(Carbon::parse('2023-12-31'));

    // Segunda config debe estar activa
    expect($secondData->fresh()->is_active)->toBeTrue()
        ->and($secondData->fresh()->valid_until)->toBeNull();
});

it('does not close configs of different users', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $data1 = CustomerFiscalData::factory()->forUser($user1->id)->active()->create();
    $data2 = CustomerFiscalData::factory()->forUser($user2->id)->active()->create();

    // Ambas deben seguir activas (usuarios diferentes)
    expect($data1->fresh()->is_active)->toBeTrue()
        ->and($data2->fresh()->is_active)->toBeTrue();
});

it('retrieves active config for user', function () {
    $user = User::factory()->create();

    CustomerFiscalData::factory()->forUser($user->id)->historical()->create();
    $active = CustomerFiscalData::factory()->forUser($user->id)->active()->create();

    $retrieved = CustomerFiscalData::getActiveForUser($user->id);

    expect($retrieved->id)->toBe($active->id)
        ->and($retrieved->is_active)->toBeTrue()
        ->and($retrieved->valid_until)->toBeNull();
});

it('retrieves config valid for user at specific date', function () {
    $user = User::factory()->create();

    // Config 2023
    $data2023 = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => Carbon::parse('2023-12-31'),
        'is_active'   => false,
    ]);

    // Config 2024 (activa)
    $data2024 = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2024-01-01'),
        'valid_until' => null,
        'is_active'   => true,
    ]);

    // Query fecha en 2023
    $validIn2023 = CustomerFiscalData::getValidForUserAt($user->id, Carbon::parse('2023-06-15'));
    expect($validIn2023->id)->toBe($data2023->id);

    // Query fecha en 2024
    $validIn2024 = CustomerFiscalData::getValidForUserAt($user->id, Carbon::parse('2024-06-15'));
    expect($validIn2024->id)->toBe($data2024->id);
});

it('belongs to user', function () {
    $user = User::factory()->create();
    $data = CustomerFiscalData::factory()->forUser($user->id)->create();

    $userModel = config('larabill.user_model');
    expect($data->user)->toBeInstanceOf($userModel)
        ->and($data->user->id)->toEqual($user->id);
});

it('distinguishes between companies and individuals', function () {
    $user = User::factory()->create();

    $company    = CustomerFiscalData::factory()->forUser($user->id)->company()->create();
    $individual = CustomerFiscalData::factory()->forUser($user->id)->individual()->create();

    expect($company->is_company)->toBeTrue()
        ->and($individual->is_company)->toBeFalse();
});

it('creates EU VAT registered customer', function () {
    $user = User::factory()->create();
    $data = CustomerFiscalData::factory()->forUser($user->id)->euVatRegistered()->create();

    expect($data->is_eu_vat_registered)->toBeTrue()
        ->and($data->is_company)->toBeTrue(); // Solo empresas pueden tener VAT
});

it('creates VAT exempt customer', function () {
    $user = User::factory()->create();
    $data = CustomerFiscalData::factory()->forUser($user->id)->vatExempt()->create();

    expect($data->is_exempt_vat)->toBeTrue();
});

it('checks if customer requires VAT correctly', function () {
    $user = User::factory()->create();

    // Cliente normal (requiere IVA)
    $normal = CustomerFiscalData::factory()->forUser($user->id)->individual()->create([
        'is_exempt_vat'        => false,
        'is_eu_vat_registered' => false,
    ]);

    // Cliente exento (NO requiere IVA)
    $exempt = CustomerFiscalData::factory()->forUser($user->id)->individual()->create([
        'is_exempt_vat' => true,
    ]);

    // Cliente B2B con VAT (NO requiere IVA - reverse charge)
    $b2b = CustomerFiscalData::factory()->forUser($user->id)->euVatRegistered()->create();

    expect($normal->requiresVAT())->toBeTrue()
        ->and($exempt->requiresVAT())->toBeFalse()
        ->and($b2b->requiresVAT())->toBeFalse(); // Reverse charge
});

it('formats full fiscal identity correctly', function () {
    $user = User::factory()->create();

    $data = CustomerFiscalData::factory()->forUser($user->id)->create([
        'fiscal_name' => 'Test Customer',
        'tax_id'      => 'ESB12345678',
    ]);

    expect($data->full_fiscal_identity)->toBe('Test Customer (ESB12345678)');
});

it('formats full address correctly', function () {
    $user = User::factory()->create();

    $data = CustomerFiscalData::factory()->forUser($user->id)->create([
        'address'      => 'Calle Test 456',
        'zip_code'     => '08001',
        'city'         => 'Barcelona',
        'state'        => 'Barcelona',
        'country_code' => 'ES',
    ]);

    expect($data->full_address)->toContain('Calle Test 456')
        ->and($data->full_address)->toContain('08001')
        ->and($data->full_address)->toContain('Barcelona');
});

it('handles missing address gracefully', function () {
    $user = User::factory()->create();

    $data = CustomerFiscalData::factory()->forUser($user->id)->withoutAddress()->create();

    expect($data->full_address)->toBe('');
});

it('scopes by user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    CustomerFiscalData::factory()->forUser($user1->id)->count(2)->create();
    CustomerFiscalData::factory()->forUser($user2->id)->count(3)->create();

    $user1Data = CustomerFiscalData::forUser($user1->id)->get();
    $user2Data = CustomerFiscalData::forUser($user2->id)->get();

    expect($user1Data)->toHaveCount(2)
        ->and($user2Data)->toHaveCount(3);
});

it('scopes companies only', function () {
    $user = User::factory()->create();

    CustomerFiscalData::factory()->forUser($user->id)->company()->create();
    CustomerFiscalData::factory()->forUser($user->id)->individual()->create();

    $companies = CustomerFiscalData::companies()->get();

    expect($companies)->toHaveCount(1)
        ->and($companies->first()->is_company)->toBeTrue();
});

it('scopes individuals only', function () {
    $user = User::factory()->create();

    CustomerFiscalData::factory()->forUser($user->id)->company()->create();
    CustomerFiscalData::factory()->forUser($user->id)->individual()->create();

    $individuals = CustomerFiscalData::individuals()->get();

    expect($individuals)->toHaveCount(1)
        ->and($individuals->first()->is_company)->toBeFalse();
});

it('scopes EU VAT registered', function () {
    $user = User::factory()->create();

    CustomerFiscalData::factory()->forUser($user->id)->euVatRegistered()->create();
    CustomerFiscalData::factory()->forUser($user->id)->create(['is_eu_vat_registered' => false]);

    $euVat = CustomerFiscalData::euVatRegistered()->get();

    expect($euVat)->toHaveCount(1)
        ->and($euVat->first()->is_eu_vat_registered)->toBeTrue();
});

it('scopes VAT exempt', function () {
    $user = User::factory()->create();

    CustomerFiscalData::factory()->forUser($user->id)->vatExempt()->create();
    CustomerFiscalData::factory()->forUser($user->id)->create(['is_exempt_vat' => false]);

    $exempt = CustomerFiscalData::vatExempt()->get();

    expect($exempt)->toHaveCount(1)
        ->and($exempt->first()->is_exempt_vat)->toBeTrue();
});

it('checks if config is currently active', function () {
    $user = User::factory()->create();

    $active     = CustomerFiscalData::factory()->forUser($user->id)->active()->create();
    $historical = CustomerFiscalData::factory()->forUser($user->id)->historical()->create();

    expect($active->isCurrentlyActive())->toBeTrue()
        ->and($historical->isCurrentlyActive())->toBeFalse();
});

it('checks if config was valid at date', function () {
    $user = User::factory()->create();

    $data = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => Carbon::parse('2023-12-31'),
    ]);

    expect($data->wasValidAt(Carbon::parse('2023-06-15')))->toBeTrue()
        ->and($data->wasValidAt(Carbon::parse('2024-06-15')))->toBeFalse()
        ->and($data->wasValidAt(Carbon::parse('2022-06-15')))->toBeFalse();
});

it('formats validity range correctly', function () {
    $user = User::factory()->create();

    $active = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2024-01-01'),
        'valid_until' => null,
    ]);

    $historical = CustomerFiscalData::factory()->forUser($user->id)->create([
        'valid_from'  => Carbon::parse('2023-01-01'),
        'valid_until' => Carbon::parse('2023-12-31'),
    ]);

    expect($active->validity_range)->toContain('Actual')
        ->and($historical->validity_range)->toContain('31/12/2023');
});
