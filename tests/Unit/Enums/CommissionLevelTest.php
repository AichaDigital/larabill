<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CommissionLevel;

describe('CommissionLevel Enum', function () {
    it('has correct values', function () {
        expect(CommissionLevel::GLOBAL->value)->toBe(0);
        expect(CommissionLevel::PRODUCT_GROUP->value)->toBe(1);
        expect(CommissionLevel::PRODUCT->value)->toBe(2);
    });

    it('returns correct labels', function () {
        expect(CommissionLevel::GLOBAL->label())->toBe('Global');
        expect(CommissionLevel::PRODUCT_GROUP->label())->toBe('Product Group');
        expect(CommissionLevel::PRODUCT->label())->toBe('Product');
    });

    it('returns correct descriptions', function () {
        expect(CommissionLevel::GLOBAL->description())->toContain('all sales');
        expect(CommissionLevel::PRODUCT_GROUP->description())->toContain('product group');
        expect(CommissionLevel::PRODUCT->description())->toContain('specific product');
    });

    it('returns correct priorities', function () {
        expect(CommissionLevel::GLOBAL->priority())->toBe(0);
        expect(CommissionLevel::PRODUCT_GROUP->priority())->toBe(1);
        expect(CommissionLevel::PRODUCT->priority())->toBe(2);
    });

    it('has higher priority for more specific levels', function () {
        expect(CommissionLevel::PRODUCT->priority())->toBeGreaterThan(CommissionLevel::PRODUCT_GROUP->priority());
        expect(CommissionLevel::PRODUCT_GROUP->priority())->toBeGreaterThan(CommissionLevel::GLOBAL->priority());
    });

    it('has all expected cases', function () {
        expect(CommissionLevel::cases())->toHaveCount(3);
    });
});
