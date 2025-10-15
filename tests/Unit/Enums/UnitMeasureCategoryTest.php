<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\UnitMeasureCategory;

describe('UnitMeasureCategory Enum', function () {
    it('has correct values', function () {
        expect(UnitMeasureCategory::COUNT->value)->toBe(0);
        expect(UnitMeasureCategory::WEIGHT->value)->toBe(1);
        expect(UnitMeasureCategory::VOLUME->value)->toBe(2);
        expect(UnitMeasureCategory::LENGTH->value)->toBe(3);
        expect(UnitMeasureCategory::AREA->value)->toBe(4);
        expect(UnitMeasureCategory::TIME->value)->toBe(5);
    });

    it('returns correct labels', function () {
        foreach (UnitMeasureCategory::cases() as $category) {
            expect($category->label())->toBeString()->not->toBeEmpty();
        }
    });

    it('converts to array', function () {
        $array = UnitMeasureCategory::toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(6);
        expect($array)->toHaveKeys([0, 1, 2, 3, 4, 5]);
    });

    it('has all expected categories', function () {
        $cases = UnitMeasureCategory::cases();

        expect($cases)->toHaveCount(6);
        expect($cases)->toContain(
            UnitMeasureCategory::COUNT,
            UnitMeasureCategory::WEIGHT,
            UnitMeasureCategory::VOLUME,
            UnitMeasureCategory::LENGTH,
            UnitMeasureCategory::AREA,
            UnitMeasureCategory::TIME
        );
    });
});
