<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use AichaDigital\Larabill\Enums\SaleIntegrityReason;
use RuntimeException;

/**
 * A voucher sale found incoherent by a re-call or by S3a/S3b (AID-1825 §1a
 * rules 3-4, §3): the claim, its payment and its invoice disagree. Thrown
 * with ZERO writes and no fiscal call; the reason is readable so the
 * consumer can show it and the `salesIncoherent()` read lists the same one.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final class SaleIntegrityException extends RuntimeException
{
    private function __construct(
        public readonly SaleIntegrityReason $reason,
        public readonly string $claimId,
        public readonly ?string $invoiceId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(SaleIntegrityReason $reason, string $claimId, ?string $invoiceId, string $detail): self
    {
        $invoice = $invoiceId !== null ? " (invoice {$invoiceId})" : '';

        return new self($reason, $claimId, $invoiceId, "Voucher sale claim {$claimId}{$invoice} is incoherent [{$reason->value}]: {$detail}. Nothing was written (AID-1825 §3).");
    }
}
