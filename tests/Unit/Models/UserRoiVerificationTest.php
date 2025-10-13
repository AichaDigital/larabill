<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\UserRoiVerification;
use Carbon\Carbon;

it('can create a user ROI verification', function () {
    $verification = UserRoiVerification::create([
        'user_id'         => '1',
        'vat_code'        => 'ESB12345678',
        'country_code'    => 'ES',
        'is_roi'          => true,
        'company_name'    => 'Test Company S.L.',
        'company_address' => 'Test Address 123',
        'last_check'      => now(),
        'expired_at'      => now()->addDays(15),
        'api_source'      => 'abstractapi',
        'response_data'   => ['valid' => true],
        'cache_hit'       => false,
    ]);

    expect($verification)->toBeInstanceOf(UserRoiVerification::class);
    expect($verification->is_roi)->toBeTrue();
    expect($verification->vat_code)->toBe('ESB12345678');
    expect($verification->country_code)->toBe('ES');
});

it('can check if cache is expired', function () {
    $verification = UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now()->subDays(20),
        'expired_at'   => now()->subDays(5), // Expired 5 days ago
    ]);

    expect($verification->isExpired())->toBeTrue();
    expect($verification->isValid())->toBeFalse();
});

it('can check if cache is valid', function () {
    $verification = UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(10), // Valid for 10 more days
    ]);

    expect($verification->isExpired())->toBeFalse();
    expect($verification->isValid())->toBeTrue();
    expect($verification->isCacheValid())->toBeTrue();
});

it('can find ROI verification by user and VAT', function () {
    UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15),
    ]);

    $found = UserRoiVerification::findByUserAndVat('1', 'ESB12345678', 'ES');

    expect($found)->not->toBeNull();
    expect($found->user_id)->toBe('1');
    expect($found->vat_code)->toBe('ESB12345678');
});

it('can find valid ROI verification', function () {
    UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15), // Valid
    ]);

    UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now()->subDays(20),
        'expired_at'   => now()->subDays(5), // Expired
    ]);

    $valid   = UserRoiVerification::findValidByUserAndVat('1', 'ESB12345678', 'ES');
    $expired = UserRoiVerification::findValidByUserAndVat('1', 'ESB87654321', 'ES');

    expect($valid)->not->toBeNull();
    expect($expired)->toBeNull();
});

it('can create or update ROI verification', function () {
    $data = [
        'user_id'         => '1',
        'vat_code'        => 'ESB12345678',
        'country_code'    => 'ES',
        'is_roi'          => true,
        'company_name'    => 'Test Company S.L.',
        'company_address' => 'Test Address 123',
        'api_source'      => 'abstractapi',
        'response_data'   => ['valid' => true],
    ];

    $verification = UserRoiVerification::createOrUpdateRoiVerification($data);

    expect($verification)->toBeInstanceOf(UserRoiVerification::class);
    expect($verification->is_roi)->toBeTrue();
    expect($verification->expired_at)->toBeGreaterThan(now());
    expect($verification->last_check)->toBeInstanceOf(Carbon::class);

    // Update with same data
    $updated = UserRoiVerification::createOrUpdateRoiVerification($data);

    expect($updated->id)->toBe($verification->id);
    expect($updated->last_check)->toBeGreaterThanOrEqual($verification->last_check);
});

it('can mark as cache hit', function () {
    $verification = UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15),
        'cache_hit'    => false,
    ]);

    $verification->markAsCacheHit();

    expect($verification->fresh()->cache_hit)->toBeTrue();
});

it('can use scopes correctly', function () {
    UserRoiVerification::create([
        'user_id'      => '1',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15), // Valid
    ]);

    UserRoiVerification::create([
        'user_id'      => '2',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'is_roi'       => false,
        'last_check'   => now()->subDays(20),
        'expired_at'   => now()->subDays(5), // Expired
    ]);

    UserRoiVerification::create([
        'user_id'      => '3',
        'vat_code'     => 'FRB12345678',
        'country_code' => 'FR',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15), // Valid
    ]);

    // Test valid scope
    $validVerifications = UserRoiVerification::valid()->get();
    expect($validVerifications)->toHaveCount(2);

    // Test expired scope
    $expiredVerifications = UserRoiVerification::expired()->get();
    expect($expiredVerifications)->toHaveCount(1);

    // Test by country scope
    $esVerifications = UserRoiVerification::byCountry('ES')->get();
    expect($esVerifications)->toHaveCount(2);

    // Test by user scope
    $userVerifications = UserRoiVerification::byUser('1')->get();
    expect($userVerifications)->toHaveCount(1);

    // Test ROI scope
    $roiVerifications = UserRoiVerification::roi()->get();
    expect($roiVerifications)->toHaveCount(2);
});

it('can get cache duration from config', function () {
    config(['larabill.roi_verification.cache_duration_days' => 30]);

    expect(UserRoiVerification::getCacheDurationDays())->toBe(30);
});

it('can check force API check setting', function () {
    config(['larabill.roi_verification.force_api_check' => true]);

    expect(UserRoiVerification::shouldForceApiCheck())->toBeTrue();

    config(['larabill.roi_verification.force_api_check' => false]);

    expect(UserRoiVerification::shouldForceApiCheck())->toBeFalse();
});

it('can get legal retention days', function () {
    config(['larabill.roi_verification.legal_retention_days' => 2555]); // 7 years

    expect(UserRoiVerification::getLegalRetentionDays())->toBe(2555);
});
