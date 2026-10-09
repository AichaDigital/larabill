<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The rectification amount is not a valid "por diferencias" base.
 *
 * ADR-014: a larabill rectificative is "por diferencias" (AEAT ClaveTipoRectificativaType
 * `I`): it REDUCES the base of the invoice it corrects, so its amount is strictly
 * negative, in base-100 unscaled cents. Zero would emit an empty document and a
 * positive amount would be an incremental correction larabill does not model.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvalidRectificationAmountException extends RuntimeException
{
    public static function forAmount(int $amount): self
    {
        return new self(
            "Rectification amount [{$amount}] is invalid: a \"por diferencias\" rectificative ".
            'requires a strictly negative base-100 amount (e.g. -5000 for €-50.00). '.
            'Pass null to rectify the full original instead.'
        );
    }
}
