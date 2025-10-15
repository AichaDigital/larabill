<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;

describe('InvoiceSerieType Enum', function () {
    it('has correct values', function () {
        expect(InvoiceSerieType::PROFORMA->value)->toBe(0);
        expect(InvoiceSerieType::INVOICE->value)->toBe(1);
        expect(InvoiceSerieType::RECTIFICATIVE->value)->toBe(2);
    });

    it('returns correct labels', function () {
        expect(InvoiceSerieType::PROFORMA->label())->toBeString();
        expect(InvoiceSerieType::INVOICE->label())->toBeString();
        expect(InvoiceSerieType::RECTIFICATIVE->label())->toBeString();
    });

    it('identifies correlation requirement correctly', function () {
        expect(InvoiceSerieType::PROFORMA->requiresCorrelation())->toBeFalse();
        expect(InvoiceSerieType::INVOICE->requiresCorrelation())->toBeTrue();
        expect(InvoiceSerieType::RECTIFICATIVE->requiresCorrelation())->toBeTrue();
    });

    it('returns correct default prefixes', function () {
        expect(InvoiceSerieType::PROFORMA->defaultPrefix())->toBe('PRO');
        expect(InvoiceSerieType::INVOICE->defaultPrefix())->toBe('FAC');
        expect(InvoiceSerieType::RECTIFICATIVE->defaultPrefix())->toBe('RECT');
    });

    it('converts to array', function () {
        $array = InvoiceSerieType::toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(3);
        expect($array)->toHaveKey(0);
        expect($array)->toHaveKey(1);
        expect($array)->toHaveKey(2);
    });
});
