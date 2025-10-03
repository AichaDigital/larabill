<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\VatVerificationService;

it('can verify a valid Spanish VAT number', function () {
    $service = new VatVerificationService;

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result['is_valid'])->toBeBool();
});

it('can verify an invalid VAT number', function () {
    $service = new VatVerificationService;

    $result = $service->verifyVatNumber('INVALID', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result['is_valid'])->toBeFalse();
});
