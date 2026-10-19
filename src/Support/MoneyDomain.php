<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use AichaDigital\Larabill\Exceptions\MoneyDomainOverflowException;

/**
 * The AEAT money domain (AID-1527, S5/S8): 12 integer digits + 2 decimals,
 * expressed in base-100 cents. The bound is derived from the AEAT limit, NOT
 * from the storage column — bigint is wider than the domain (S8).
 *
 * The domain is SYMMETRIC: negatives are preserved (rectificatives/credit
 * lines, SettlementService sign semantics) and zero is valid (AID-956: cero
 * es un precio válido).
 *
 * This is the consultable surface: consumers query it instead of reinventing
 * the bound. It applies to the FISCAL-DOCUMENT-AMOUNT class of columns (§2
 * matrix: invoices.*, invoice_items money columns, article_prices.price,
 * article_overrides.custom_price, article_service_status.effective_price,
 * articles.cost_price, commissions.* incl. the dual-purpose rate,
 * article_guarantee_operations.amount). Accumulated/policy values
 * (eu_sales_thresholds.*, grouped_payments.amount) and quantity carry NO
 * AEAT cap — only the bigint column bound.
 *
 * @api Supported public surface (AID-1527).
 */
final class MoneyDomain
{
    /**
     * The AEAT money bound in base-100 cents: 999,999,999,999.99 (12+2 digits).
     */
    public const MAX_MONEY_CENTS = 99_999_999_999_999;

    /**
     * Whether a cents value sits inside the AEAT money domain — symmetric:
     * valid for both signs, zero valid.
     */
    public static function isValidMoneyCents(int $cents): bool
    {
        return $cents >= -self::MAX_MONEY_CENTS
            && $cents <= self::MAX_MONEY_CENTS;
    }

    /**
     * Loud rejection of a cents value outside the AEAT money domain.
     *
     * @throws MoneyDomainOverflowException
     */
    public static function assertValidMoneyCents(int $cents): void
    {
        if (! self::isValidMoneyCents($cents)) {
            throw MoneyDomainOverflowException::forCents($cents);
        }
    }
}
