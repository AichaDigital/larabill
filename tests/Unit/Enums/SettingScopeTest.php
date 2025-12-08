<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\SettingScope;

describe('SettingScope Enum', function () {
    it('has correct values', function () {
        expect(SettingScope::GLOBAL_SCOPE->value)->toBe(0);
        expect(SettingScope::CLIENT->value)->toBe(1);
        expect(SettingScope::INDIVIDUAL->value)->toBe(2);
    });

    it('returns correct labels', function () {
        expect(SettingScope::GLOBAL_SCOPE->label())->toBe('Global');
        expect(SettingScope::CLIENT->label())->toBe('Client');
        expect(SettingScope::INDIVIDUAL->label())->toBe('Individual');
    });

    it('returns correct descriptions', function () {
        expect(SettingScope::GLOBAL_SCOPE->description())->toContain('all invoices');
        expect(SettingScope::CLIENT->description())->toContain('specific client');
        expect(SettingScope::INDIVIDUAL->description())->toContain('individual invoices');
    });

    it('has all expected cases', function () {
        expect(SettingScope::cases())->toHaveCount(3);
    });
});
