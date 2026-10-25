<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use RuntimeException;

/**
 * An allocation amount is not a valid positive, cent-exact value.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvalidAllocationException extends RuntimeException
{
    public static function nonPositive(FixedDecimal $amount): self
    {
        return new self("Allocation amount [{$amount->unscaledValue()}] (scale {$amount->scale()}) must be strictly positive.");
    }

    public static function subCentAmount(FixedDecimal $amount): self
    {
        return new self("Allocation amount [{$amount->unscaledValue()}] (scale {$amount->scale()}) is sub-cent: allocations must be exact to the cent.");
    }
}
