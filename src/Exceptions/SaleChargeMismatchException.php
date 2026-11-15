<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\SaleReservation;

/**
 * A charge that does not match the frozen expectation of its one-off sale
 * (AID-1997 spec section 4.3 S1, D3/D4): the expectation is read from the
 * proforma, never passed in, and a mismatching charge is refused with ZERO
 * writes — the sale never records money it cannot reconcile.
 *
 * @api Supported public surface (AID-1997; see docs/api-surface.md).
 */
final class SaleChargeMismatchException extends \RuntimeException
{
    public static function amount(SaleReservation $reservation, int $charged, int $expected): self
    {
        return new self(
            "The charge of {$charged} (base-100) does not match the frozen expectation {$expected} of the one-off sale [{$reservation->operation_key}]; nothing was written."
        );
    }

    public static function payer(SaleReservation $reservation, string $charged, string $expected): self
    {
        return new self(
            "The charging payer [{$charged}] is not the expected billable user [{$expected}] of the one-off sale [{$reservation->operation_key}]; nothing was written."
        );
    }

    public static function currency(SaleReservation $reservation, string $charged, string $expected): self
    {
        return new self(
            "The charge currency [{$charged}] is not the frozen expectation [{$expected}] of the one-off sale [{$reservation->operation_key}]; nothing was written."
        );
    }

    public static function proformaDrift(SaleReservation $reservation, string $reason): self
    {
        return new self(
            "The locked proforma of the one-off sale [{$reservation->operation_key}] drifted from the frozen expectation ({$reason}); nothing was written."
        );
    }
}
