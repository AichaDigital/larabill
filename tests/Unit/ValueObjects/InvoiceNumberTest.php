<?php

declare(strict_types=1);

use AichaDigital\Larabill\ValueObjects\InvoiceNumber;

describe('InvoiceNumber', function () {
    it('stores all components', function () {
        $number = new InvoiceNumber(
            formatted: 'FAC-2025-000047',
            prefix: 'FAC',
            fiscalYear: 2025,
            seriesNumber: 47,
        );

        expect($number->formatted)->toBe('FAC-2025-000047');
        expect($number->prefix)->toBe('FAC');
        expect($number->fiscalYear)->toBe(2025);
        expect($number->seriesNumber)->toBe(47);
    });

    it('casts to string via __toString', function () {
        $number = new InvoiceNumber(
            formatted: 'PRO-2026-000001',
            prefix: 'PRO',
            fiscalYear: 2026,
            seriesNumber: 1,
        );

        expect((string) $number)->toBe('PRO-2026-000001');
    });

    it('works in string concatenation', function () {
        $number = new InvoiceNumber(
            formatted: 'FAC-2025-000003',
            prefix: 'FAC',
            fiscalYear: 2025,
            seriesNumber: 3,
        );

        expect('Invoice: ' . $number)->toBe('Invoice: FAC-2025-000003');
    });

    it('is readonly', function () {
        $number = new InvoiceNumber(
            formatted: 'FAC-2025-000001',
            prefix: 'FAC',
            fiscalYear: 2025,
            seriesNumber: 1,
        );

        $threw = false;
        try {
            $number->formatted = 'CHANGED';
        } catch (\Error $e) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    });
});
