<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\TaxCalculation\TaxCalculationStrategy;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Services\TaxCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockStrategy = Mockery::mock(TaxCalculationStrategy::class);
    $this->service      = new TaxCalculationService($this->mockStrategy);
    $this->taxGroup     = TaxGroup::factory()->create();
});

it('can be instantiated with a strategy', function () {
    expect($this->service)->toBeInstanceOf(TaxCalculationService::class);
});

it('can be instantiated without a strategy', function () {
    // Mock the container to return a strategy
    $mockStrategy = Mockery::mock(TaxCalculationStrategy::class);
    app()->instance(TaxCalculationStrategy::class, $mockStrategy);

    $service = new TaxCalculationService;

    expect($service)->toBeInstanceOf(TaxCalculationService::class);
});

it('can set a new strategy', function () {
    $newStrategy = Mockery::mock(TaxCalculationStrategy::class);

    $result = $this->service->setStrategy($newStrategy);

    expect($result)->toBe($this->service); // Fluent interface
});

it('can calculate taxes using the strategy', function () {
    $baseAmount     = 100.00;
    $context        = ['country' => 'ES'];
    $expectedResult = [
        'taxes' => [
            ['name' => 'VAT', 'rate' => 21.00, 'amount' => 21.00],
        ],
        'total_tax' => 21.00,
        'total'     => 121.00,
    ];

    $this->mockStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, $context)
        ->andReturn($expectedResult);

    $result = $this->service->calculate($baseAmount, $this->taxGroup, $context);

    expect($result)->toBe($expectedResult)
        ->and($result['total_tax'])->toBe(21.00)
        ->and($result['total'])->toBe(121.00);
});

it('passes empty context by default', function () {
    $baseAmount     = 100.00;
    $expectedResult = [
        'taxes'     => [],
        'total_tax' => 0.00,
        'total'     => 100.00,
    ];

    $this->mockStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, [])
        ->andReturn($expectedResult);

    $result = $this->service->calculate($baseAmount, $this->taxGroup);

    expect($result)->toBe($expectedResult);
});

it('can switch strategies dynamically', function () {
    $firstStrategy  = Mockery::mock(TaxCalculationStrategy::class);
    $secondStrategy = Mockery::mock(TaxCalculationStrategy::class);

    $service    = new TaxCalculationService($firstStrategy);
    $baseAmount = 100.00;

    // First calculation with first strategy
    $firstStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, [])
        ->andReturn(['total_tax' => 21.00]);

    $result1 = $service->calculate($baseAmount, $this->taxGroup);
    expect($result1['total_tax'])->toBe(21.00);

    // Switch strategy
    $service->setStrategy($secondStrategy);

    // Second calculation with second strategy
    $secondStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, [])
        ->andReturn(['total_tax' => 10.00]);

    $result2 = $service->calculate($baseAmount, $this->taxGroup);
    expect($result2['total_tax'])->toBe(10.00);
});

it('can handle complex context', function () {
    $baseAmount = 250.00;
    $context    = [
        'country'      => 'US',
        'state'        => 'CA',
        'customer_id'  => 123,
        'product_type' => 'digital',
    ];

    $expectedResult = [
        'taxes' => [
            ['name' => 'State Tax', 'rate' => 7.25, 'amount' => 18.13],
            ['name' => 'County Tax', 'rate' => 1.00, 'amount' => 2.50],
        ],
        'total_tax' => 20.63,
        'total'     => 270.63,
    ];

    $this->mockStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, $context)
        ->andReturn($expectedResult);

    $result = $this->service->calculate($baseAmount, $this->taxGroup, $context);

    expect($result)->toBe($expectedResult)
        ->and($result['taxes'])->toHaveCount(2)
        ->and($result['total_tax'])->toBe(20.63);
});

it('can handle zero amount', function () {
    $baseAmount     = 0.00;
    $expectedResult = [
        'taxes'     => [],
        'total_tax' => 0.00,
        'total'     => 0.00,
    ];

    $this->mockStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, [])
        ->andReturn($expectedResult);

    $result = $this->service->calculate($baseAmount, $this->taxGroup);

    expect($result['total_tax'])->toBe(0.00);
});

it('can handle negative amount', function () {
    $baseAmount     = -50.00;
    $expectedResult = [
        'taxes'     => [],
        'total_tax' => -10.50,
        'total'     => -60.50,
    ];

    $this->mockStrategy
        ->shouldReceive('calculate')
        ->once()
        ->with($baseAmount, $this->taxGroup, [])
        ->andReturn($expectedResult);

    $result = $this->service->calculate($baseAmount, $this->taxGroup);

    expect($result['total_tax'])->toBe(-10.50);
});

it('returns result from strategy without modification', function () {
    $baseAmount     = 100.00;
    $strategyResult = [
        'custom_field' => 'custom_value',
        'nested'       => ['data' => 'value'],
    ];

    $this->mockStrategy
        ->shouldReceive('calculate')
        ->once()
        ->andReturn($strategyResult);

    $result = $this->service->calculate($baseAmount, $this->taxGroup);

    expect($result)->toBe($strategyResult);
});

it('can handle multiple sequential calculations', function () {
    $amounts = [100.00, 200.00, 300.00];

    foreach ($amounts as $amount) {
        $this->mockStrategy
            ->shouldReceive('calculate')
            ->once()
            ->with($amount, $this->taxGroup, [])
            ->andReturn(['total_tax' => $amount * 0.21]);

        $result = $this->service->calculate($amount, $this->taxGroup);
        expect($result['total_tax'])->toBe($amount * 0.21);
    }
});
