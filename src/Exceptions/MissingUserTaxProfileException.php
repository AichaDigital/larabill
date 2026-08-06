<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * Recurring emission requires the receiver to carry a valid UserTaxProfile
 * with a non-empty fiscal identity (tax_id) at emission time (AID-836).
 *
 * Unattended emission must not degrade silently: without the receiver's
 * fiscal identity the invoice would be classified as a simplified (F2)
 * invoice by the fiscal registration layer with nobody there to notice.
 * The affected service is counted as failed for the run, nothing is
 * persisted and no fiscal number is consumed.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class MissingUserTaxProfileException extends RuntimeException
{
    public static function missingProfile(int|string $serviceId, string $receiverId): self
    {
        return new self(
            "Cannot bill recurring service {$serviceId}: receiver {$receiverId} has no ".
            'valid UserTaxProfile at emission time. Create an active profile with its '.
            'fiscal identity before activating recurring billing for this customer.'
        );
    }

    public static function missingTaxId(int|string $serviceId, string $receiverId): self
    {
        return new self(
            "Cannot bill recurring service {$serviceId}: receiver {$receiverId} has a ".
            'valid UserTaxProfile but its tax_id is empty. Recurring emission refuses '.
            'to issue fiscally unidentified (simplified) invoices; fill in the tax_id.'
        );
    }
}
