<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;

describe('InvoiceStatus Enum', function () {
    it('has correct values', function () {
        expect(InvoiceStatus::DRAFT->value)->toBe(0);
        expect(InvoiceStatus::SENT->value)->toBe(1);
        expect(InvoiceStatus::PAID->value)->toBe(2);
        expect(InvoiceStatus::OVERDUE->value)->toBe(3);
        expect(InvoiceStatus::CANCELLED->value)->toBe(4);
    });

    it('returns correct labels', function () {
        foreach (InvoiceStatus::cases() as $status) {
            expect($status->label())->toBeString()->not->toBeEmpty();
        }
    });

    it('identifies editable status correctly', function () {
        expect(InvoiceStatus::DRAFT->canBeEdited())->toBeTrue();
        expect(InvoiceStatus::SENT->canBeEdited())->toBeFalse();
        expect(InvoiceStatus::PAID->canBeEdited())->toBeFalse();
        expect(InvoiceStatus::OVERDUE->canBeEdited())->toBeFalse();
        expect(InvoiceStatus::CANCELLED->canBeEdited())->toBeFalse();
    });

    it('converts to array', function () {
        $array = InvoiceStatus::toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveCount(5);
        expect($array)->toHaveKeys([0, 1, 2, 3, 4]);
    });
});

