<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;

it('pins the movement kind values', function () {
    expect(CreditMovementKind::SURPLUS->value)->toBe(0)
        ->and(CreditMovementKind::TOP_UP->value)->toBe(1)
        ->and(CreditMovementKind::APPLIED->value)->toBe(2)
        ->and(CreditMovementKind::REFUNDED->value)->toBe(3);
});

it('splits kinds into entries and exits', function () {
    expect(CreditMovementKind::SURPLUS->isEntry())->toBeTrue()
        ->and(CreditMovementKind::TOP_UP->isEntry())->toBeTrue()
        ->and(CreditMovementKind::APPLIED->isExit())->toBeTrue()
        ->and(CreditMovementKind::REFUNDED->isExit())->toBeTrue()
        ->and(CreditMovementKind::APPLIED->isEntry())->toBeFalse()
        ->and(CreditMovementKind::SURPLUS->isExit())->toBeFalse();
});

it('exposes non-empty labels and a 4-entry array', function () {
    foreach (CreditMovementKind::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty()->not->toContain('larabill::');
    }
    expect(CreditMovementKind::toArray())->toHaveCount(4);
});
