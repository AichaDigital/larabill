<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AllocationStatus;

it('has ACTIVE=0 and REVERSED=1', function () {
    expect(AllocationStatus::ACTIVE->value)->toBe(0)
        ->and(AllocationStatus::REVERSED->value)->toBe(1);
});

it('exposes non-empty labels and a 2-entry array', function () {
    foreach (AllocationStatus::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty()->not->toContain('larabill::');
    }
    expect(AllocationStatus::toArray())->toHaveKeys([0, 1])->toHaveCount(2);
});
