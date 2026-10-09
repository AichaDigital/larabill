<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\InvoiceItem;
use RuntimeException;

/**
 * The emitted line cannot be measured with the proportional unconsumed-time
 * arithmetic (ADR-014 eje 2).
 *
 * ADR-014: the unconsumed base is proportional to the time not consumed of
 * the EMITTED line — the only arithmetic larabill offers. It needs the
 * line's own service period (`service_date_from` / `service_date_to`) and a
 * non-negative billed base: larabill never invents a period (AID-589
 * doctrine) and a credit line carries no consumable value to distribute.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class NonProportionableLineException extends RuntimeException
{
    public static function forMissingServicePeriod(InvoiceItem $line): self
    {
        return new self(
            "Invoice line [{$line->description}] has no complete service period and cannot be ".
            'measured proportionally: the unconsumed base needs the line\'s own '.
            'service_date_from and service_date_to — larabill never invents a period (AID-589 doctrine).'
        );
    }

    public static function forInvertedPeriod(InvoiceItem $line): self
    {
        return new self(
            "Invoice line [{$line->description}] has an inverted service period ".
            "(service_date_to [{$line->service_date_to?->format('Y-m-d')}] before ".
            "service_date_from [{$line->service_date_from?->format('Y-m-d')}]) and cannot be ".
            'measured proportionally.'
        );
    }

    public static function forNegativeBase(InvoiceItem $line): self
    {
        return new self(
            "Invoice line [{$line->description}] is a credit line (taxable amount ".
            "[{$line->taxable_amount->unscaledValue()}] in base-100 cents) and is not ".
            'proportionable: a negative line carries no consumable value to distribute.'
        );
    }
}
