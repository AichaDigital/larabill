<?php

declare(strict_types=1);

use AichaDigital\Larabill\Exceptions\MoneyDomainOverflowException;
use AichaDigital\Larabill\Support\MoneyDomain;

/**
 * AID-1527 §2.2 (S5/S8) — the AEAT money domain as a consultable surface.
 *
 * The bound is derived from the AEAT limit (12 integer digits + 2 decimals =
 * 999,999,999,999.99 = 99_999_999_999_999 cents), NOT from the storage column
 * (bigint is wider than the domain). The domain is SYMMETRIC — rectificatives
 * and credit lines carry negative amounts — and zero is valid (AID-956:
 * cero es un precio válido).
 *
 * Consumers query this surface instead of reinventing the bound.
 */
describe('AID-1527 §2.2 — MoneyDomain (AEAT 12+2 digits, symmetric)', function () {

    it('exposes the AEAT bound as 99_999_999_999_999 cents (12 integer + 2 decimal digits)', function () {
        expect(MoneyDomain::MAX_MONEY_CENTS)->toBe(99_999_999_999_999);
    });

    it('accepts the positive AEAT bound exactly', function () {
        expect(MoneyDomain::isValidMoneyCents(MoneyDomain::MAX_MONEY_CENTS))->toBeTrue();
    });

    it('accepts the negative AEAT bound exactly (symmetric: rectificatives carry negatives)', function () {
        expect(MoneyDomain::isValidMoneyCents(-MoneyDomain::MAX_MONEY_CENTS))->toBeTrue();
    });

    it('accepts zero (AID-956: cero es un precio válido)', function () {
        expect(MoneyDomain::isValidMoneyCents(0))->toBeTrue();
    });

    it('rejects one cent beyond the positive bound', function () {
        expect(MoneyDomain::isValidMoneyCents(MoneyDomain::MAX_MONEY_CENTS + 1))->toBeFalse();
    });

    it('rejects one cent beyond the negative bound (round-1 mutation: a naive > MAX check accepts -MAX-1)', function () {
        expect(MoneyDomain::isValidMoneyCents(-MoneyDomain::MAX_MONEY_CENTS - 1))->toBeFalse();
    });

    it('assertValidMoneyCents returns silently inside the domain', function () {
        MoneyDomain::assertValidMoneyCents(0);
        MoneyDomain::assertValidMoneyCents(MoneyDomain::MAX_MONEY_CENTS);
        MoneyDomain::assertValidMoneyCents(-MoneyDomain::MAX_MONEY_CENTS);

        expect(true)->toBeTrue();
    });

    it('assertValidMoneyCents throws the typed MoneyDomainOverflowException above the positive bound', function () {
        MoneyDomain::assertValidMoneyCents(MoneyDomain::MAX_MONEY_CENTS + 1);
    })->throws(MoneyDomainOverflowException::class);

    it('assertValidMoneyCents throws the typed MoneyDomainOverflowException below the negative bound', function () {
        MoneyDomain::assertValidMoneyCents(-MoneyDomain::MAX_MONEY_CENTS - 1);
    })->throws(MoneyDomainOverflowException::class);

    it('carries the column context in its named constructor (column, bound, received)', function () {
        $exception = MoneyDomainOverflowException::forColumn(
            'invoices.total_amount',
            100_000_000_000_000
        );

        expect($exception)->toBeInstanceOf(MoneyDomainOverflowException::class)
            ->and($exception->getMessage())->toContain('invoices.total_amount')
            ->and($exception->getMessage())->toContain('100000000000000')
            ->and($exception->getMessage())->toContain('99999999999999');
    });

});
