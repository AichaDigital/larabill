<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Models\SaleReservation;
use RuntimeException;

/**
 * A proforma is not reservable for a one-off sale (AID-1997 spec §4.1 step 5,
 * D3): not a proforma, a collection status outside {DRAFT, SENT}, CONVERTING
 * or CONVERTED, claimed by a voucher sale, a non-positive total, no fiscal
 * config currency — or an operation key already spent on a Released
 * reservation of this proforma.
 *
 * @api Supported public surface (AID-1997; see docs/api-surface.md).
 */
final class SaleNotReservableException extends RuntimeException
{
    public static function forStatus(SaleReservation $reservation): self
    {
        return new self(
            "The operation key [{$reservation->operation_key}] is spent: reservation [{$reservation->id}] of proforma [{$reservation->proforma_id}] was released and the key never re-enters."
        );
    }

    public static function notAProforma(string $invoiceId): self
    {
        return new self("Invoice [{$invoiceId}] is not a proforma and cannot be reserved for a one-off sale.");
    }

    public static function forStatusValue(string $invoiceId, int $status): self
    {
        return new self("Proforma [{$invoiceId}] has collection status {$status}; only DRAFT or SENT proformas are reservable.");
    }

    public static function forConverted(string $invoiceId): self
    {
        return new self("Proforma [{$invoiceId}] is already CONVERTING or CONVERTED and cannot be reserved again.");
    }

    public static function forVoucherClaim(string $invoiceId, string $claimId): self
    {
        return new self("Proforma [{$invoiceId}] carries an open voucher sale claim [{$claimId}]: a proforma is claimed by one sale at a time.");
    }

    public static function forNonPositiveTotal(string $invoiceId): self
    {
        return new self("Proforma [{$invoiceId}] has a non-positive total; nothing can be sold against it.");
    }

    public static function forNoCurrency(string $invoiceId): self
    {
        return new self("Proforma [{$invoiceId}] has no fiscal config currency to freeze an expectation against.");
    }
}
