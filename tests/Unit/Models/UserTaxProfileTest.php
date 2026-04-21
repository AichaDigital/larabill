<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\UserTaxProfile;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('UserTaxProfile Model', function () {
    beforeEach(function () {
        // Create a user for the tests (ADR-004: owner_user_id)
        $this->ownerId = Str::uuid()->toString();
    });

    it('can create a user tax profile', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'          => $this->ownerId,
            'fiscal_name'            => 'John Doe',
            'tax_id'                 => 'ES12345678A',
            'legal_entity_type_code' => 'PERSON',
            'address'                => '123 Main Street',
            'city'                   => 'Madrid',
            'state'                  => 'Madrid',
            'zip_code'               => '28001',
            'country_code'           => 'ES',
            'is_company'             => false,
            'is_eu_vat_registered'   => false,
            'is_exempt_vat'          => false,
            'valid_from'             => now(),
            'is_active'              => true,
        ]);

        expect($profile)->toBeInstanceOf(UserTaxProfile::class);
        expect($profile->fiscal_name)->toBe('John Doe');
    });

    it('casts booleans correctly', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Test User',
            'country_code'          => 'ES',
            'is_company'            => 1,
            'is_eu_vat_registered'  => 0,
            'is_exempt_vat'         => 1,
            'valid_from'            => now(),
            'is_active'             => 1,
        ]);

        expect($profile->is_company)->toBeBool()->toBeTrue();
        expect($profile->is_eu_vat_registered)->toBeBool()->toBeFalse();
        expect($profile->is_exempt_vat)->toBeBool()->toBeTrue();
        expect($profile->is_active)->toBeBool()->toBeTrue();
    });

    it('casts dates correctly', function () {
        $validFrom  = now()->startOfDay();
        $validUntil = now()->addYear()->startOfDay();

        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Test User',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => $validFrom,
            'valid_until'           => $validUntil,
            'is_active'             => true,
        ]);

        expect($profile->valid_from)->toBeInstanceOf(Carbon::class);
        expect($profile->valid_until)->toBeInstanceOf(Carbon::class);
    });

    it('can get active profile for owner', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Active Profile',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $active = UserTaxProfile::getActiveForOwner($this->ownerId);

        expect($active)->not->toBeNull();
        expect($active->fiscal_name)->toBe('Active Profile');
    });

    it('can get profile valid at specific date', function () {
        $oldDate = now()->subYear();
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Historical Profile',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => $oldDate,
            'valid_until'           => now()->subMonth(),
            'is_active'             => false,
        ]);

        $foundProfile = UserTaxProfile::getValidForOwnerAt($this->ownerId, $oldDate->addMonth());

        expect($foundProfile)->not->toBeNull();
        expect($foundProfile->id)->toBe($profile->id);
    });

    it('can create profile for owner', function () {
        $profile = UserTaxProfile::createForOwner($this->ownerId, [
            'fiscal_name'           => 'New Profile',
            'country_code'          => 'DE',
            'is_company'            => true,
            'is_eu_vat_registered'  => true,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
        ]);

        expect($profile->is_active)->toBeTrue();
        expect($profile->valid_until)->toBeNull();
        expect($profile->fiscal_name)->toBe('New Profile');
    });

    it('can scope for owner', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Owner Profile',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $profiles = UserTaxProfile::forOwner($this->ownerId)->get();

        expect($profiles)->toHaveCount(1);
    });

    it('can scope active profiles', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Active',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $ownerId2 = Str::uuid()->toString();
        UserTaxProfile::create([
            'owner_user_id'         => $ownerId2,
            'fiscal_name'           => 'Inactive',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now()->subYear(),
            'valid_until'           => now()->subMonth(),
            'is_active'             => false,
        ]);

        $activeProfiles = UserTaxProfile::active()->get();

        expect($activeProfiles)->toHaveCount(1);
        expect($activeProfiles->first()->fiscal_name)->toBe('Active');
    });

    it('can scope companies', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Company',
            'country_code'          => 'ES',
            'is_company'            => true,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $companies = UserTaxProfile::companies()->get();

        expect($companies)->toHaveCount(1);
    });

    it('can scope individuals', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Individual',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $individuals = UserTaxProfile::individuals()->get();

        expect($individuals)->toHaveCount(1);
    });

    it('can scope EU VAT registered', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'EU Registered',
            'country_code'          => 'DE',
            'is_company'            => true,
            'is_eu_vat_registered'  => true,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $euRegistered = UserTaxProfile::euVatRegistered()->get();

        expect($euRegistered)->toHaveCount(1);
    });

    it('can scope VAT exempt', function () {
        UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Exempt',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => true,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $exempt = UserTaxProfile::vatExempt()->get();

        expect($exempt)->toHaveCount(1);
    });

    it('can check if currently active', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Active Profile',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        expect($profile->isCurrentlyActive())->toBeTrue();
    });

    it('can check if was valid at date', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Historical',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now()->subYear(),
            'valid_until'           => now()->subMonth(),
            'is_active'             => false,
        ]);

        expect($profile->wasValidAt(now()->subMonths(6)))->toBeTrue();
        expect($profile->wasValidAt(now()))->toBeFalse();
    });

    it('returns validity range attribute', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Test',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => Carbon::create(2024, 1, 1),
            'is_active'             => true,
        ]);

        expect($profile->validity_range)->toContain('01/01/2024');
        expect($profile->validity_range)->toContain('Current');
    });

    it('returns full fiscal identity attribute', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'John Doe',
            'tax_id'                => 'ES12345678A',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        expect($profile->full_fiscal_identity)->toBe('John Doe (ES12345678A)');
    });

    it('returns full address attribute', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Test User',
            'address'               => '123 Main Street',
            'zip_code'              => '28001',
            'city'                  => 'Madrid',
            'state'                 => 'Madrid',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        expect($profile->full_address)->toContain('123 Main Street');
        expect($profile->full_address)->toContain('Madrid');
    });

    it('requires VAT when not exempt and not EU registered', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Normal User',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        expect($profile->requiresVAT())->toBeTrue();
    });

    it('does not require VAT when exempt', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Exempt User',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => true,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        expect($profile->requiresVAT())->toBeFalse();
    });

    it('does not require VAT when EU registered (reverse charge)', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'EU Company',
            'country_code'          => 'DE',
            'is_company'            => true,
            'is_eu_vat_registered'  => true,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        expect($profile->requiresVAT())->toBeFalse();
    });

    it('uses soft deletes', function () {
        $profile = UserTaxProfile::create([
            'owner_user_id'         => $this->ownerId,
            'fiscal_name'           => 'Test User',
            'country_code'          => 'ES',
            'is_company'            => false,
            'is_eu_vat_registered'  => false,
            'is_exempt_vat'         => false,
            'valid_from'            => now(),
            'is_active'             => true,
        ]);

        $profile->delete();

        expect(UserTaxProfile::count())->toBe(0);
        expect(UserTaxProfile::withTrashed()->count())->toBe(1);
    });
});
