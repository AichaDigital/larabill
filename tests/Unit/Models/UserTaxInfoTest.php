<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\UserTaxProfile;

it('can create a user tax info record', function () {
    $taxInfo = new UserTaxInfo([
        'user_id'      => 1,
        'is_current'   => true,
        'tax_code'       => 'ESB12345678',
        'company_name' => 'Test Company S.L.',
        'address'      => 'Calle Test 123',
        'city'         => 'Madrid',
        'postal_code'  => '28001',
        'country'      => 'ES',
        'state'        => 'Madrid',
        'phone'        => '+34 600 000 000',
    ]);

    expect($taxInfo->user_id)->toBe(1);
    expect($taxInfo->is_current)->toBeTrue();
    expect($taxInfo->tax_code)->toBe('ESB12345678');
    expect($taxInfo->company_name)->toBe('Test Company S.L.');
    expect($taxInfo->address)->toBe('Calle Test 123');
    expect($taxInfo->city)->toBe('Madrid');
    expect($taxInfo->postal_code)->toBe('28001');
    expect($taxInfo->country)->toBe('ES');
    expect($taxInfo->state)->toBe('Madrid');
    expect($taxInfo->phone)->toBe('+34 600 000 000');
});

it('can make a tax info current', function () {
    $taxInfo = UserTaxProfile::create([
        'user_id'      => 1,
        'is_current'   => false,
        'tax_code'       => 'ESB12345678',
        'company_name' => 'Test Company S.L.',
        'address'      => 'Calle Test 123',
        'city'         => 'Madrid',
        'postal_code'  => '28001',
        'country'      => 'ES',
        'state'        => 'Madrid',
        'phone'        => '+34 600 000 000',
    ]);

    $taxInfo->makeCurrent();

    expect($taxInfo->is_current)->toBeTrue();
});

it('can scope current tax info', function () {
    // Create multiple tax info records for same user
    UserTaxProfile::create([
        'user_id'      => 1,
        'is_current'   => false,
        'tax_code'       => 'ESB11111111',
        'company_name' => 'Old Company',
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
        'company_name' => 'Current Company',
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
