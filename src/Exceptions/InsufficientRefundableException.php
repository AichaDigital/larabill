<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\SaleReservation;

/**
 * A refund amount exceeds the remaining refundable capacity of its sale
 * (AID-1997 spec §4.5 R1, D8 rev11): capacity is the settled amount minus
 * the amounts of non-`Abandoned` refund rows, computed under the
 * reservation lock — two concurrent refunds with different keys cannot
 * exceed the settled amount.
 *
 * @api Supported public surface (AID-1997; see docs/api-surface.md).
 */
final class InsufficientRefundableException extends \RuntimeException
{
    public static function forRefund(SaleReservation $reservation, FixedDecimal $requested, FixedDecimal $remaining): self
    {
        return new self(
            "The refund of {$requested->toDecimalString()} exceeds the remaining refundable {$remaining->toDecimalString()} of the one-off sale [{$reservation->operation_key}]; nothing was written."
        );
    }
}
