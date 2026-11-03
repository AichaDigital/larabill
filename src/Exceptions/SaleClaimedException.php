<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * A write refused by the sale-integrity boundary (AID-1825 §1a rules 1-2):
 * the row belongs to a voucher sale in progress and only `sellVoucher()`
 * may change it. Rows without a claim are never refused by this exception.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final class SaleClaimedException extends RuntimeException
{
    public static function invoice(string $invoiceId, string $claimId, string $operation): self
    {
        return new self("Invoice {$invoiceId} belongs to voucher sale claim {$claimId}: {$operation} is refused outside sellVoucher() (AID-1825 §1a).");
    }

    public static function proforma(string $proformaId, string $claimId, string $operation): self
    {
        return new self("Proforma {$proformaId} is claimed by voucher sale {$claimId}: {$operation} is refused outside sellVoucher() (AID-1825 §1a).");
    }

    public static function allocation(int $allocationId, string $invoiceId, string $claimId): self
    {
        return new self("Allocation {$allocationId} is the collection of voucher sale claim {$claimId} on invoice {$invoiceId}: it cannot be reversed (AID-1825 §1a rule 2).");
    }
}
