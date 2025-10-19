<?php

declare(strict_types=1);

use AichaDigital\Larabill\DataTransferObjects\PricingDetails;

it('can create a pricing details DTO', function () {
    $dto = new PricingDetails(
        basePrice: 29.00,
        appliedPrice: 24.00,
        pricingRule: 'customer_override',
        discountAmount: 5.00,
        discountPercentage: 17.24,
        overrideId: 789,
        additional: ['note' => 'Special discount']
    );

    expect($dto->basePrice)->toBe(29.00)
        ->and($dto->appliedPrice)->toBe(24.00)
        ->and($dto->pricingRule)->toBe('customer_override')
        ->and($dto->discountAmount)->toBe(5.00)
        ->and($dto->discountPercentage)->toBe(17.24)
        ->and($dto->overrideId)->toBe(789)
        ->and($dto->additional)->toBe(['note' => 'Special discount']);
});

it('can create pricing details from array', function () {
    $data = [
        'base_price'          => 29.00,
        'applied_price'       => 24.00,
        'pricing_rule'        => 'customer_override',
        'discount_amount'     => 5.00,
        'discount_percentage' => 17.24,
        'override_id'         => 789,
        'additional'          => ['note' => 'Special discount'],
    ];

    $dto = PricingDetails::fromArray($data);

    expect($dto->basePrice)->toBe(29.00)
        ->and($dto->appliedPrice)->toBe(24.00)
        ->and($dto->pricingRule)->toBe('customer_override')
        ->and($dto->discountAmount)->toBe(5.00)
        ->and($dto->discountPercentage)->toBe(17.24)
        ->and($dto->overrideId)->toBe(789);
});

it('can convert pricing details to array', function () {
    $dto = new PricingDetails(
        basePrice: 29.00,
        appliedPrice: 24.00,
        pricingRule: 'customer_override',
        discountAmount: 5.00,
        discountPercentage: 17.24,
        overrideId: 789
    );

    $array = $dto->toArray();

    expect($array)->toMatchArray([
        'base_price'          => 29.00,
        'applied_price'       => 24.00,
        'pricing_rule'        => 'customer_override',
        'discount_amount'     => 5.00,
        'discount_percentage' => 17.24,
        'override_id'         => 789,
    ]);
});

it('handles optional pricing details', function () {
    $dto = new PricingDetails(
        basePrice: 29.00,
        appliedPrice: 29.00
    );

    expect($dto->basePrice)->toBe(29.00)
        ->and($dto->appliedPrice)->toBe(29.00)
        ->and($dto->pricingRule)->toBeNull()
        ->and($dto->discountAmount)->toBeNull()
        ->and($dto->discountPercentage)->toBeNull()
        ->and($dto->overrideId)->toBeNull();
});
