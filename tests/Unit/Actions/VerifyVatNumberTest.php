<?php

declare(strict_types=1);

use AichaDigital\Larabill\Actions\VerifyVatNumber;
use Aichadigital\Lararoi\Contracts\VatVerificationServiceInterface;
use Aichadigital\Lararoi\Exceptions\VatVerificationException;

it('delegates verification to the lararoi contract and returns the canonical result unchanged', function () {
    $canonical = [
        'is_valid'        => true,
        'vat_code'        => 'B12345678',
        'country_code'    => 'ES',
        'company_name'    => 'ACME SL',
        'company_address' => 'Calle Falsa 123, Madrid',
        'api_source'      => 'vies_soap',
        'cached'          => false,
        'request_date'    => '2026-07-03',
    ];

    $service = Mockery::mock(VatVerificationServiceInterface::class);
    $service->shouldReceive('verifyVatNumber')
        ->once()
        ->with('B12345678', 'ES') // passed verbatim: no prefix stripping, no normalization
        ->andReturn($canonical);

    app()->instance(VatVerificationServiceInterface::class, $service);

    $result = VerifyVatNumber::run('B12345678', 'ES');

    expect($result)->toBe($canonical);
});

it('propagates the lararoi verification exception (transparent bridge)', function () {
    $service = Mockery::mock(VatVerificationServiceInterface::class);
    $service->shouldReceive('verifyVatNumber')
        ->once()
        ->andThrow(new VatVerificationException('VIES unavailable'));

    app()->instance(VatVerificationServiceInterface::class, $service);

    VerifyVatNumber::run('B12345678', 'ES');
})->throws(VatVerificationException::class, 'VIES unavailable');
