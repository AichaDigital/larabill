<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CancellationType;

it('has correct values for each case', function () {
    expect(CancellationType::IMMEDIATE->value)->toBe(0)
        ->and(CancellationType::END_OF_PERIOD->value)->toBe(1)
        ->and(CancellationType::NOTICE_PERIOD->value)->toBe(2);
});

it('returns correct labels', function () {
    expect(CancellationType::IMMEDIATE->label())->toBe('Immediate cancellation')
        ->and(CancellationType::END_OF_PERIOD->label())->toBe('End of billing period')
        ->and(CancellationType::NOTICE_PERIOD->label())->toBe('Notice period with refund');
});

it('returns correct descriptions', function () {
    expect(CancellationType::IMMEDIATE->description())
        ->toContain('immediately without refund')
        ->and(CancellationType::END_OF_PERIOD->description())
        ->toContain('end of current billing period')
        ->and(CancellationType::NOTICE_PERIOD->description())
        ->toContain('with refund for unused time');
});

it('identifies refund requirement correctly', function () {
    expect(CancellationType::IMMEDIATE->requiresRefund())->toBeFalse()
        ->and(CancellationType::END_OF_PERIOD->requiresRefund())->toBeFalse()
        ->and(CancellationType::NOTICE_PERIOD->requiresRefund())->toBeTrue();
});

it('identifies immediate deprovisioning correctly', function () {
    expect(CancellationType::IMMEDIATE->shouldDeprovisionImmediately())->toBeTrue()
        ->and(CancellationType::END_OF_PERIOD->shouldDeprovisionImmediately())->toBeFalse()
        ->and(CancellationType::NOTICE_PERIOD->shouldDeprovisionImmediately())->toBeFalse();
});

it('identifies continuation until end of period correctly', function () {
    expect(CancellationType::IMMEDIATE->continuesUntilEndOfPeriod())->toBeFalse()
        ->and(CancellationType::END_OF_PERIOD->continuesUntilEndOfPeriod())->toBeTrue()
        ->and(CancellationType::NOTICE_PERIOD->continuesUntilEndOfPeriod())->toBeFalse();
});

it('returns correct icons for each type', function () {
    expect(CancellationType::IMMEDIATE->icon())->toBe('x-circle')
        ->and(CancellationType::END_OF_PERIOD->icon())->toBe('calendar-x')
        ->and(CancellationType::NOTICE_PERIOD->icon())->toBe('alert-circle');
});

it('returns correct colors for each type', function () {
    expect(CancellationType::IMMEDIATE->color())->toBe('red')
        ->and(CancellationType::END_OF_PERIOD->color())->toBe('orange')
        ->and(CancellationType::NOTICE_PERIOD->color())->toBe('yellow');
});

it('can be used in match expressions for business logic', function () {
    $shouldGenerateCreditNote = fn (CancellationType $type) => match ($type) {
        CancellationType::IMMEDIATE, CancellationType::END_OF_PERIOD => false,
        CancellationType::NOTICE_PERIOD                              => true,
    };

    expect($shouldGenerateCreditNote(CancellationType::IMMEDIATE))->toBeFalse()
        ->and($shouldGenerateCreditNote(CancellationType::END_OF_PERIOD))->toBeFalse()
        ->and($shouldGenerateCreditNote(CancellationType::NOTICE_PERIOD))->toBeTrue();
});

it('can be used in match expressions for effective date calculation', function () {
    $getEffectiveDateType = fn (CancellationType $type) => match ($type) {
        CancellationType::IMMEDIATE     => 'now',
        CancellationType::END_OF_PERIOD => 'next_billing_date',
        CancellationType::NOTICE_PERIOD => 'notice_days',
    };

    expect($getEffectiveDateType(CancellationType::IMMEDIATE))->toBe('now')
        ->and($getEffectiveDateType(CancellationType::END_OF_PERIOD))->toBe('next_billing_date')
        ->and($getEffectiveDateType(CancellationType::NOTICE_PERIOD))->toBe('notice_days');
});
