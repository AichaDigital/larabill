<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\GroupedPaymentStatus;

it('has POSTED=0 and REVERSED=1', function () {
    expect(GroupedPaymentStatus::POSTED->value)->toBe(0)
        ->and(GroupedPaymentStatus::REVERSED->value)->toBe(1);
});

it('exposes non-empty labels and a 2-entry array', function () {
    expect(GroupedPaymentStatus::POSTED->label())->toBeString()->not->toBeEmpty()
        ->and(GroupedPaymentStatus::toArray())->toHaveKeys([0, 1])->toHaveCount(2);
});
