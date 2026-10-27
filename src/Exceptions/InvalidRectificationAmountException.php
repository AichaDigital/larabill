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

    /**
     * Additive refusal of AID-1778 (spec §5.1, G10): the supplied frozen
     * `$items` and the explicit difference `$amount` are two alternative
     * amount sources (eje 2) — exactly one may be supplied. An explicit
     * amount alongside items would re-derive what the items already freeze.
     */
    public static function itemsWithAmount(): self
    {
        return new self(
            'Rectification refused: an explicit amount and supplied items are mutually exclusive amount sources. Pass the frozen $items with a null amount (the items carry their own totals), or an amount with null items.'
        );
    }

    /**
     * Additive validation of AID-1778 (spec §5.1, G10): the supplied items
     * fail a rectificative invariant before any write — every line's
     * `total_amount` strictly negative with `taxable_amount +
     * total_tax_amount === total_amount`, every `taxes_applied` entry with
     * `amount <= 0` naming a `(source_rate_id, rate)` pair the original's
     * frozen breakdown actually carries (a rate the original never had is
     * an invented distribution, AID-589 doctrine), per item Σ
     * `taxes_applied[].amount === total_tax_amount`, across items Σ
     * `total_amount < 0`, and the list never empty.
     */
    public static function invalidItems(string $reason): self
    {
        return new self(
            "Supplied rectification items are invalid: {$reason} Nothing was written."
        );
    }
}
