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
    // Mock RoiVerificationService
    $mockRoiService = Mockery::mock(\AichaDigital\Larabill\Services\RoiVerificationService::class);
    $mockRoiService->shouldReceive('verifyRoiStatus')
        ->andReturn(['is_roi' => true]);

    $service = new TaxCalculationService(null, $mockRoiService);

    $options = [
        'user_id' => 'test-user',
        'vat_verification' => [
            'vat_number' => 'DE123456789',
            'country_code' => 'DE',
        ],
    ];

    $result = $service->calculateTax(100.0, 'ES', 'DE', true, $options);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('tax_rate');
    expect($result)->toHaveKey('tax_amount');
    expect($result)->toHaveKey('tax_type');
    expect($result['tax_rate'])->toBe(0.0);
    expect($result['tax_amount'])->toBe(0.0);
    expect($result['tax_type'])->toBe('reverse_charge');
});

it('can calculate Canarias IGIC tax', function () {
    $service = new TaxCalculationService;

    $result = $service->calculateTax(100.0, 'ES', 'IC', false);

    expect($result)->toBeArray();
    expect($result['tax_rate'])->toBe(7.0);
    expect($result['tax_amount'])->toBeFloat();
    expect($result['tax_amount'])->toBeGreaterThan(6.99);
    expect($result['tax_amount'])->toBeLessThan(7.01);
    expect($result['tax_type'])->toBe('igic');
    expect($result['tax_name'])->toBe('IGIC');
    expect($result['special_conditions'])->toContain('canarias');
});

it('can calculate Ceuta/Melilla IPSI tax', function () {
    $service = new TaxCalculationService;

    $result = $service->calculateTax(100.0, 'ES', 'CE', false);

    expect($result)->toBeArray();
    expect($result['tax_rate'])->toBe(0.0);
    expect($result['tax_amount'])->toBe(0.0);
    expect($result['tax_type'])->toBe('ipsi');
    expect($result['tax_name'])->toBe('IPSI');
    expect($result['special_conditions'])->toContain('ceuta_melilla');
});

it('can calculate USA Sales Tax', function () {
    $service = new TaxCalculationService;

    $options = ['state' => 'CA'];
    $result = $service->calculateTax(100.0, 'ES', 'US', false, $options);

    expect($result)->toBeArray();
    expect($result['tax_rate'])->toBe(7.25);
    expect($result['tax_amount'])->toBeFloat();
    expect($result['tax_amount'])->toBeGreaterThan(7.24);
    expect($result['tax_amount'])->toBeLessThan(7.26);
    expect($result['tax_type'])->toBe('sales_tax');
    expect($result['tax_name'])->toBe('Sales Tax');
    expect($result['special_conditions'])->toContain('usa');
});

it('can calculate worldwide tax (no VAT)', function () {
    $service = new TaxCalculationService;

    $result = $service->calculateTax(100.0, 'ES', 'JP', false);

    expect($result)->toBeArray();
    expect($result['tax_rate'])->toBe(0.0);
    expect($result['tax_amount'])->toBe(0.0);
    expect($result['tax_type'])->toBe('none');
    expect($result['tax_name'])->toBe('Sin impuestos');
    expect($result['special_conditions'])->toContain('worldwide');
});

it('can calculate Spanish tax with different categories', function () {
    $service = new TaxCalculationService;

    // Standard rate
    $result = $service->calculateSpanishTax(100.0, 'ES', 'ES', false);
    expect($result['tax_rate'])->toBe(21.0);

    // Reduced rate
    $result = $service->calculateSpanishTax(100.0, 'ES', 'ES', false, ['category' => 'reduced']);
    expect($result['tax_rate'])->toBe(10.0);

    // Super reduced rate
    $result = $service->calculateSpanishTax(100.0, 'ES', 'ES', false, ['category' => 'super_reduced']);
    expect($result['tax_rate'])->toBe(4.0);

    // Exempt
    $result = $service->calculateSpanishTax(100.0, 'ES', 'ES', false, ['category' => 'exempt']);
    expect($result['tax_rate'])->toBe(0.0);
});
