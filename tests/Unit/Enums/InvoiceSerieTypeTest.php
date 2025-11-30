<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;

describe('InvoiceSerieType Enum', function () {
    it('has correct values', function () {
        expect(InvoiceSerieType::PROFORMA->value)->toBe(0);
        expect(InvoiceSerieType::INVOICE->value)->toBe(1);
        expect(InvoiceSerieType::SIMPLIFIED->value)->toBe(2);
        expect(InvoiceSerieType::RECTIFICATIVE->value)->toBe(3);
    });

    it('returns correct labels', function () {
        expect(InvoiceSerieType::PROFORMA->label())->toBe('proforma');
        expect(InvoiceSerieType::INVOICE->label())->toBe('invoice');
        expect(InvoiceSerieType::SIMPLIFIED->label())->toBe('simplified');
        expect(InvoiceSerieType::RECTIFICATIVE->label())->toBe('rectificative');
    });

    it('identifies correlation requirement correctly', function () {
        expect(InvoiceSerieType::PROFORMA->requiresCorrelation())->toBeFalse();
        expect(InvoiceSerieType::INVOICE->requiresCorrelation())->toBeTrue();
        expect(InvoiceSerieType::SIMPLIFIED->requiresCorrelation())->toBeTrue();
        expect(InvoiceSerieType::RECTIFICATIVE->requiresCorrelation())->toBeTrue();
    });

    it('returns correct default prefixes', function () {
        expect(InvoiceSerieType::PROFORMA->defaultPrefix())->toBe('PRO');
        expect(InvoiceSerieType::INVOICE->defaultPrefix())->toBe('FAC');
        expect(InvoiceSerieType::SIMPLIFIED->defaultPrefix())->toBe('TIK');
        expect(InvoiceSerieType::RECTIFICATIVE->defaultPrefix())->toBe('RECT');
    });

    it('identifies fiscal types correctly', function () {
        expect(InvoiceSerieType::PROFORMA->isFiscal())->toBeFalse();
        expect(InvoiceSerieType::INVOICE->isFiscal())->toBeTrue();
        expect(InvoiceSerieType::SIMPLIFIED->isFiscal())->toBeTrue();
        expect(InvoiceSerieType::RECTIFICATIVE->isFiscal())->toBeTrue();
    });

    it('identifies full customer data requirement correctly', function () {
        expect(InvoiceSerieType::PROFORMA->requiresFullCustomerData())->toBeFalse();
        expect(InvoiceSerieType::INVOICE->requiresFullCustomerData())->toBeTrue();
        expect(InvoiceSerieType::SIMPLIFIED->requiresFullCustomerData())->toBeFalse();
        expect(InvoiceSerieType::RECTIFICATIVE->requiresFullCustomerData())->toBeTrue();
    });

    it('converts to array', function () {
        $array = InvoiceSerieType::toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(4);
        expect($array)->toHaveKey(0);
        expect($array)->toHaveKey(1);
        expect($array)->toHaveKey(2);
        expect($array)->toHaveKey(3);
    });

    it('returns fiscal types', function () {
        $fiscalTypes = InvoiceSerieType::fiscalTypes();

        expect($fiscalTypes)->toHaveCount(3);
        expect($fiscalTypes)->toContain(InvoiceSerieType::INVOICE);
        expect($fiscalTypes)->toContain(InvoiceSerieType::SIMPLIFIED);
        expect($fiscalTypes)->toContain(InvoiceSerieType::RECTIFICATIVE);
        expect($fiscalTypes)->not->toContain(InvoiceSerieType::PROFORMA);
    });
});
