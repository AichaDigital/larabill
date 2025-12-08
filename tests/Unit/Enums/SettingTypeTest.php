<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\SettingType;

describe('SettingType Enum', function () {
    it('has correct values', function () {
        expect(SettingType::TEMPLATE->value)->toBe(0);
        expect(SettingType::NOTES->value)->toBe(1);
        expect(SettingType::PAYMENT_TERMS->value)->toBe(2);
    });

    it('returns correct labels', function () {
        expect(SettingType::TEMPLATE->label())->toBe('Template');
        expect(SettingType::NOTES->label())->toBe('Notes');
        expect(SettingType::PAYMENT_TERMS->label())->toBe('Payment Terms');
    });

    it('returns correct descriptions', function () {
        expect(SettingType::TEMPLATE->description())->toContain('template');
        expect(SettingType::NOTES->description())->toContain('notes');
        expect(SettingType::PAYMENT_TERMS->description())->toContain('Payment terms');
    });

    it('has all expected cases', function () {
        expect(SettingType::cases())->toHaveCount(3);
    });
});
