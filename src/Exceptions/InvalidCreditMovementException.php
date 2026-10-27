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

    /**
     * Additive refusal of AID-1778 (spec §4.2): a reversed entry has been
     * undone in the ledger, so no advance invoice can document its collection.
     */
    public static function reversed(string $movementId): self
    {
        return new self("Credit movement {$movementId} is reversed and cannot back an advance invoice.");
    }
}
