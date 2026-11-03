<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\VoucherSaleStage;
use RuntimeException;

/**
 * A payment operation refused by the stage of the voucher sale it funds
 * (AID-1825 §1a rule 5): `reverse()` is allowed at PENDING only.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final class SaleStageException extends RuntimeException
{
    public static function reverseRefused(string $paymentId, string $claimId, VoucherSaleStage $stage): self
    {
        return new self("Payment {$paymentId} funds voucher sale claim {$claimId} at stage {$stage->name}: it can only be reversed while the sale is pending (AID-1825 §1a rule 5).");
    }
}
