<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\ItemType;

describe('ItemType Enum', function () {
    it('has correct values', function () {
        expect(ItemType::GOOD->value)->toBe(0);
        expect(ItemType::SERVICE->value)->toBe(1);
    });

    it('returns correct labels', function () {
        expect(ItemType::GOOD->label())->toBeString();
        expect(ItemType::SERVICE->label())->toBeString();
        expect(ItemType::GOOD->label())->not->toBeEmpty();
        expect(ItemType::SERVICE->label())->not->toBeEmpty();
    });

    it('identifies when service dates are required', function () {
        expect(ItemType::GOOD->requiresServiceDates())->toBeFalse();
        expect(ItemType::SERVICE->requiresServiceDates())->toBeTrue();
    });

    it('converts to array', function () {
        $array = ItemType::toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(2);
        expect($array)->toHaveKey(0);
        expect($array)->toHaveKey(1);
        expect($array[0])->toBeString();
        expect($array[1])->toBeString();
    });

    it('can get all cases', function () {
        $cases = ItemType::cases();

        expect($cases)->toHaveCount(2);
        expect($cases[0])->toBeInstanceOf(ItemType::class);
        expect($cases[1])->toBeInstanceOf(ItemType::class);
    });
});

