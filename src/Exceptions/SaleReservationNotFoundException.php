<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

/**
 * No one-off reservation exists for an operation key (AID-1997 spec
 * section 4.3): settle() works an EXISTING reservation — there is no
 * implicit reserve. A released reservation is spent and never found again
 * by its key.
 *
 * @api Supported public surface (AID-1997; see docs/api-surface.md).
 */
final class SaleReservationNotFoundException extends \RuntimeException
{
    public static function forKey(string $operationKey): self
    {
        return new self(
            "No one-off sale reservation exists for the operation key [{$operationKey}]; reserve() it first (a released key is spent forever)."
        );
    }
}
