<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The requested allocations exceed what the payment still has available (base-100).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class AllocationExceedsPaymentException extends RuntimeException
{
    public static function forPayment(string $paymentId, int $availableUnscaled, int $requestedUnscaled): self
    {
        return new self("Payment {$paymentId} cannot allocate: requested {$requestedUnscaled}, available {$availableUnscaled} (base-100).");
    }
}
