<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\ArticleServiceStatus;
use RuntimeException;

/**
 * An agreement reached the pricing path without a contractual price.
 *
 * Since AID-956 the recurring engine bills `effective_price` as contractual
 * state (ADR-004) and never re-derives the amount from the catalogue. When
 * that value is absent there is nothing to fall back to: guessing from the
 * catalogue would reprice a live agreement, and the previous `?? 0.0` silently
 * issued an invoice for nothing. Same doctrine as AID-589 with the fiscal
 * series — a business value the consumer owns is never invented.
 *
 * Absent means `null` and only `null`. **A contract price of zero is valid**
 * and is billed as zero.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingContractPriceException extends RuntimeException
{
    public static function forRevision(ArticleServiceStatus $service): self
    {
        $reference = $service->getKey() ?? $service->instance_identifier ?? 'unpersisted';

        return new self(
            "Cannot revise the contractual price of service agreement [{$reference}]: ".
            'no active override for the customer and no catalogue price for its billing frequency. '.
            'The previous price has been kept — set an ArticlePrice for that frequency, '.
            'or an ArticleOverride for the customer, before revising.'
        );
    }

    public static function forService(ArticleServiceStatus $service): self
    {
        $reference = $service->getKey() ?? $service->instance_identifier ?? 'unpersisted';

        return new self(
            "Service agreement [{$reference}] carries no contractual price (effective_price is null), ".
            'so there is no amount to bill. Set effective_price on the agreement — '.
            'ArticleServiceStatus::updateEffectivePrice() derives it from the catalogue or an override.'
        );
    }
}
