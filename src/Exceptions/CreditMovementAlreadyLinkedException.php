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

    /**
     * The advance invoice is already linked to ANOTHER credit entry — the
     * one-to-one link holds in BOTH directions (AID-1778 §5.6, O2/J1). Thrown
     * from the conflict set materialized in PHP after the advance invoice's
     * row lock, BEFORE any UPDATE: the refused entry's own link stays NULL,
     * so `forMovement()` cannot name the blocker — this constructor names
     * the WINNING entry instead.
     *
     * @api Additive named constructor (AID-1778, 9.0).
     */
    public static function forAdvance(string $advanceInvoiceId, string $winningEntryId): self
    {
        return new self(sprintf(
            'Advance invoice %s is already linked to credit entry %s — the link is one-to-one in both directions. This entry\'s link was NOT written (it stays unlinked).',
            $advanceInvoiceId,
            $winningEntryId,
        ));
    }
}
