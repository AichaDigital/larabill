<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The credit movement is already linked to an advance invoice.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class CreditMovementAlreadyLinkedException extends RuntimeException
{
    public static function forMovement(string $movementId, string $advanceInvoiceId): self
    {
        return new self("Credit movement {$movementId} is already linked to advance invoice {$advanceInvoiceId}.");
    }
}
