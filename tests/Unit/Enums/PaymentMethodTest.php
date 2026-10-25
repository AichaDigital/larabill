<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\PaymentMethod;

it('pins the payment method values', function () {
    expect(PaymentMethod::BankTransfer->value)->toBe(0)
        ->and(PaymentMethod::Card->value)->toBe(1)
        ->and(PaymentMethod::Cash->value)->toBe(2)
        ->and(PaymentMethod::Other->value)->toBe(3);
});

it('exposes non-empty labels and a 4-entry array', function () {
    foreach (PaymentMethod::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty()->not->toContain('larabill::');
    }
    expect(PaymentMethod::toArray())->toHaveKeys([0, 1, 2, 3])->toHaveCount(4);
});
