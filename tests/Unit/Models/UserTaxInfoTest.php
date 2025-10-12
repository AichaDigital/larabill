<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\UserTaxProfile;

it('can create a user tax profile record', function () {
    $taxProfile = new UserTaxProfile([
        'user_id'       => 1,
        'is_current'    => true,
        'tax_code'      => 'ESB12345678',
        'business_name' => 'Test Company S.L.',
        'address'      => 'Calle Test 123',
        'city'         => 'Madrid',
        'postal_code'  => '28001',
        'country'      => 'ES',
        'state'        => 'Madrid',
        'phone'        => '+34 600 000 000',
    ]);

    expect($taxProfile->user_id)->toBe(1);
    expect($taxProfile->is_current)->toBeTrue();
    expect($taxProfile->tax_code)->toBe('ESB12345678');
    expect($taxProfile->business_name)->toBe('Test Company S.L.');
    expect($taxProfile->address)->toBe('Calle Test 123');
    expect($taxProfile->city)->toBe('Madrid');
    expect($taxProfile->postal_code)->toBe('28001');
    expect($taxProfile->country)->toBe('ES');
    expect($taxProfile->state)->toBe('Madrid');
    expect($taxProfile->phone)->toBe('+34 600 000 000');
});

it('can make a tax info current', function () {
    $taxProfile = UserTaxProfile::create([
        'user_id'      => 1,
        'is_current'   => false,
        'tax_code'       => 'ESB12345678',
        'business_name' => 'Test Company S.L.',
        'address'      => 'Calle Test 123',
        'city'         => 'Madrid',
        'postal_code'  => '28001',
        'country'      => 'ES',
        'state'        => 'Madrid',
        'phone'        => '+34 600 000 000',
    ]);

    $taxProfile->makeCurrent();

    expect($taxProfile->is_current)->toBeTrue();
});

it('can scope current tax info', function () {
    // Create multiple tax info records for same user
    UserTaxProfile::create([
        'user_id'      => 1,
        'is_current'   => false,
        'tax_code'       => 'ESB11111111',
        'business_name' => 'Old Company',
        'address'      => 'Old Address',
        'city'         => 'Barcelona',
        'postal_code'  => '08001',
        'country'      => 'ES',
        'state'        => 'Barcelona',
        'phone'        => '+34 600 000 001',
    ]);

    $currentTaxInfo = UserTaxProfile::create([
        'user_id'      => 1,
        'is_current'   => true,
        'tax_code'       => 'ESB12345678',
        'business_name' => 'Current Company',
        'address'      => 'Current Address',
        'city'         => 'Madrid',
        'postal_code'  => '28001',
        'country'      => 'ES',
        'state'        => 'Madrid',
        'phone'        => '+34 600 000 002',
    ]);

    $currentRecords = UserTaxProfile::current()->get();

    expect($currentRecords)->toHaveCount(1);
    expect($currentRecords->first()->id)->toBe($currentTaxInfo->id);
});
