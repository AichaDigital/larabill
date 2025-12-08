<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CommissionType;

describe('CommissionType Enum', function () {
    it('has correct values', function () {
        expect(CommissionType::PERCENTAGE->value)->toBe(0);
        expect(CommissionType::FIXED->value)->toBe(1);
    });

    it('returns correct labels', function () {
        expect(CommissionType::PERCENTAGE->label())->toBe('Percentage');
        expect(CommissionType::FIXED->label())->toBe('Fixed Amount');
    });

    it('returns correct descriptions', function () {
        expect(CommissionType::PERCENTAGE->description())->toContain('percentage');
        expect(CommissionType::FIXED->description())->toContain('fixed monetary amount');
    });

    it('has all expected cases', function () {
        expect(CommissionType::cases())->toHaveCount(2);
    });
});
