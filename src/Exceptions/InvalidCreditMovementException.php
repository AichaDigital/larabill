<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The credit movement is not valid for the requested operation.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvalidCreditMovementException extends RuntimeException
{
    public static function notAnEntry(string $movementId): self
    {
        return new self("Credit movement {$movementId} is not an entry (surplus or top-up) and cannot be used here.");
    }
}
