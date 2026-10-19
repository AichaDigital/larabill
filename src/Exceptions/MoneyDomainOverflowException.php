<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Support\MoneyDomain;
use RuntimeException;

/**
 * A money value exceeds the AEAT money domain (AID-1527): the absolute value
 * of a fiscal-document amount must stay within 99_999_999_999_999 cents
 * (12 integer digits + 2 decimals). Zero is valid; negatives are preserved
 * (rectificatives/credit lines).
 *
 * Rejection surfaces (§2.1): this typed exception raises on Eloquent
 * ASSIGNMENT paths (through AichaMoneyCast); on BYPASS paths
 * (where()->update(), upsert(), raw SQL, relation pivots) the engine CHECK
 * raises Laravel's QueryException instead — both documented, neither
 * rewritten.
 *
 * @api Supported public surface (AID-1527).
 */
final class MoneyDomainOverflowException extends RuntimeException
{
    /**
     * Column-context rejection: names the column, the bound and the value
     * received. The bound defaults to the AEAT money domain.
     */
    public static function forColumn(string $column, int $received, ?int $bound = null): self
    {
        return new self(sprintf(
            'Money amount [%d] for column [%s] exceeds the AEAT money domain bound [%d] cents (12 integer digits + 2 decimals). Zero is valid and negatives are allowed (rectificatives).',
            $received,
            $column,
            $bound ?? MoneyDomain::MAX_MONEY_CENTS
        ));
    }

    /**
     * Domain-level rejection (no column context available).
     */
    public static function forCents(int $received): self
    {
        return new self(sprintf(
            'Money amount [%d] exceeds the AEAT money domain bound [%d] cents (12 integer digits + 2 decimals). Zero is valid and negatives are allowed (rectificatives).',
            $received,
            MoneyDomain::MAX_MONEY_CENTS
        ));
    }
}
