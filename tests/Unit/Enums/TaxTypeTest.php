<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\TaxType;

describe('TaxType Enum', function () {
    it('has correct values', function () {
        expect(TaxType::VAT->value)->toBe('vat')
            ->and(TaxType::SALES_TAX->value)->toBe('sales_tax')
            ->and(TaxType::GST->value)->toBe('gst')
            ->and(TaxType::OTHER->value)->toBe('other');
    });

    it('returns correct labels', function () {
        expect(TaxType::VAT->label())->toBe('Value Added Tax')
            ->and(TaxType::SALES_TAX->label())->toBe('Sales Tax')
            ->and(TaxType::GST->label())->toBe('Goods and Services Tax')
            ->and(TaxType::OTHER->label())->toBe('Other');
    });

    it('can get all values as array', function () {
        $values = TaxType::values();

        expect($values)->toBeArray()
            ->and($values)->toContain('vat')
            ->and($values)->toContain('sales_tax')
            ->and($values)->toContain('gst')
            ->and($values)->toContain('other')
            ->and($values)->toHaveCount(4);
    });

    it('can get all cases', function () {
        $cases = TaxType::cases();

        expect($cases)->toHaveCount(4)
            ->and($cases[0])->toBeInstanceOf(TaxType::class);
    });

    it('converts to array correctly', function () {
        $values = TaxType::values();

        expect($values)->toBeArray()
            ->and(count($values))->toBe(count(TaxType::cases()));
    });
});
