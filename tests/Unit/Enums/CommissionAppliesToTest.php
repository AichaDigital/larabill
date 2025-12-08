<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CommissionAppliesTo;

describe('CommissionAppliesTo Enum', function () {
    it('has correct values', function () {
        expect(CommissionAppliesTo::TAXABLE_AMOUNT->value)->toBe(0);
        expect(CommissionAppliesTo::TOTAL_AMOUNT->value)->toBe(1);
    });

    it('returns correct labels', function () {
        expect(CommissionAppliesTo::TAXABLE_AMOUNT->label())->toBe('Taxable Amount');
        expect(CommissionAppliesTo::TOTAL_AMOUNT->label())->toBe('Total Amount');
    });

    it('returns correct descriptions', function () {
        expect(CommissionAppliesTo::TAXABLE_AMOUNT->description())->toContain('base amount before taxes');
        expect(CommissionAppliesTo::TOTAL_AMOUNT->description())->toContain('total amount including taxes');
    });

    it('has all expected cases', function () {
        expect(CommissionAppliesTo::cases())->toHaveCount(2);
    });
});
