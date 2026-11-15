<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Enums\VoucherSaleStage;
use RuntimeException;

/**
 * A sale stage refused a transition (two sale flows share this exception):
 *
 * - the voucher sale (AID-1825 §1a rule 5): `reverse()` of the funding
 *   payment is allowed at PENDING only;
 * - the one-off sale (AID-1997 spec §4.2): `release()` is allowed at
 *   Reserved only — at Paid or later the sale resumes FORWARD, it does not
 *   go back (§8 Q1 = A).
 *
 * @api Supported public surface (AID-1825, AID-1997; see docs/api-surface.md).
 */
final class SaleStageException extends RuntimeException
{
    public static function reverseRefused(string $paymentId, string $claimId, VoucherSaleStage $stage): self
    {
        return new self("Payment {$paymentId} funds voucher sale claim {$claimId} at stage {$stage->name}: it can only be reversed while the sale is pending (AID-1825 §1a rule 5).");
    }

    public static function forRelease(SaleReservationStage $stage, string $operationKey): self
    {
        return new self(
            "The one-off sale [{$operationKey}] is at stage {$stage->name}; release() is allowed at Reserved only — a paid sale resumes forward, it does not go back."
        );
    }
}
