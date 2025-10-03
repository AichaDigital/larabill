<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\VatVerification;

it('can create a VAT verification record', function () {
    $verification = new VatVerification([
        'vat_number' => 'ESB12345678',
        'country_code' => 'ES',
        'is_valid' => true,
        'company_name' => 'Test Company S.L.',
        'company_address' => 'Calle Test 123, Madrid',
        'api_source' => 'abstractapi',
        'response_data' => [
            'valid' => true,
            'company_name' => 'Test Company S.L.',
            'address' => 'Calle Test 123, Madrid',
        ],
    ]);

    expect($verification->vat_number)->toBe('ESB12345678');
    expect($verification->country_code)->toBe('ES');
    expect($verification->is_valid)->toBeTrue();
    expect($verification->company_name)->toBe('Test Company S.L.');
    expect($verification->company_address)->toBe('Calle Test 123, Madrid');
    expect($verification->api_source)->toBe('abstractapi');
    expect($verification->response_data)->toBeArray();
    expect($verification->response_data['valid'])->toBeTrue();
});

it('can scope valid VAT verifications', function () {
    VatVerification::create([
        'vat_number' => 'ESB11111111',
        'country_code' => 'ES',
        'is_valid' => true,
        'company_name' => 'Valid Company',
        'company_address' => 'Valid Address',
        'api_source' => 'abstractapi',
        'response_data' => ['valid' => true],
    ]);

    VatVerification::create([
        'vat_number' => 'ESB22222222',
        'country_code' => 'ES',
        'is_valid' => false,
        'company_name' => null,
        'company_address' => null,
        'api_source' => 'abstractapi',
        'response_data' => ['valid' => false],
    ]);

    $validVerifications = VatVerification::valid()->get();

    expect($validVerifications)->toHaveCount(1);
    expect($validVerifications->first()->vat_number)->toBe('ESB11111111');
});

it('can find verification by VAT number and country', function () {
    $verification = VatVerification::create([
        'vat_number' => 'ESB12345678',
        'country_code' => 'ES',
        'is_valid' => true,
        'company_name' => 'Test Company S.L.',
        'company_address' => 'Calle Test 123, Madrid',
        'api_source' => 'abstractapi',
        'response_data' => ['valid' => true],
    ]);

    $found = VatVerification::findByVatNumber('ESB12345678', 'ES');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($verification->id);
    expect($found->company_name)->toBe('Test Company S.L.');
});
