<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Exceptions\InvalidAllocationException;

/**
 * A requested slice of a payment applied to one invoice.
 *
 * Invariant: the amount is strictly positive and exact to the cent
 * (whatever its scale), checked in that order.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final readonly class Allocation
{
    public function __construct(
        public string $invoiceId,
        public FixedDecimal $amount,
    ) {
        if ($amount->compareTo(FixedDecimal::ofUnscaled(0, $amount->scale())) <= 0) {
            throw InvalidAllocationException::nonPositive($amount);
        }

        if ($amount->toScale(2)->compareTo($amount) !== 0) {
            throw InvalidAllocationException::subCentAmount($amount);
        }
    }
}
