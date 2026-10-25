<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The requested allocation exceeds the invoice outstanding amount (base-100).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class AllocationExceedsInvoiceException extends RuntimeException
{
    public static function forInvoice(string $invoiceId, int $outstandingUnscaled, int $requestedUnscaled): self
    {
        return new self("Invoice {$invoiceId} cannot be allocated: requested {$requestedUnscaled}, outstanding {$outstandingUnscaled} (base-100).");
    }
}
