<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\TemplateInvoiceType;

describe('TemplateInvoiceType Enum', function () {
    it('has correct values', function () {
        expect(TemplateInvoiceType::FISCAL->value)->toBe(0);
        expect(TemplateInvoiceType::PROFORMA->value)->toBe(1);
        expect(TemplateInvoiceType::REVERSE_CHARGE->value)->toBe(2);
        expect(TemplateInvoiceType::EXEMPT->value)->toBe(3);
    });

    it('returns correct labels', function () {
        expect(TemplateInvoiceType::FISCAL->label())->toBe('Fiscal');
        expect(TemplateInvoiceType::PROFORMA->label())->toBe('Proforma');
        expect(TemplateInvoiceType::REVERSE_CHARGE->label())->toBe('Reverse Charge');
        expect(TemplateInvoiceType::EXEMPT->label())->toBe('Exempt');
    });

    it('returns correct descriptions', function () {
        expect(TemplateInvoiceType::FISCAL->description())->toContain('fiscal');
        expect(TemplateInvoiceType::PROFORMA->description())->toContain('Proforma');
        expect(TemplateInvoiceType::REVERSE_CHARGE->description())->toContain('reverse charge');
        expect(TemplateInvoiceType::EXEMPT->description())->toContain('exempt');
    });

    it('has all expected cases', function () {
        expect(TemplateInvoiceType::cases())->toHaveCount(4);
    });
});
