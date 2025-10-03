<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\TaxCalculationService;

it('can calculate Spanish VAT for domestic transactions', function () {
    $service = new TaxCalculationService;

    $result = $service->calculateTax(100.0, 'ES', 'ES', false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('tax_rate');
    expect($result)->toHaveKey('tax_amount');
    expect($result['tax_rate'])->toBe(21.0);
    expect($result['tax_amount'])->toBe(21.0);
});

it('can calculate reverse charge for EU B2B transactions', function () {
    $service = new TaxCalculationService;

    $result = $service->calculateTax(100.0, 'ES', 'DE', true);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('tax_rate');
    expect($result)->toHaveKey('tax_amount');
    expect($result['tax_rate'])->toBe(0.0);
    expect($result['tax_amount'])->toBe(0.0);
});
