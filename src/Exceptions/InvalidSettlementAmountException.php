<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The settlement amount is not a valid unconsumed value to liquidate.
 *
 * ADR-014: a settlement is a NEW ordinary invoice whose credit line
 * liquidates a strictly positive unconsumed value, in base-100 unscaled
 * cents (e.g. 5000 = €50.00). Zero means there is nothing to liquidate —
 * that is the "ninguno" artifact (no document at all), not an empty invoice.
 * A negative amount corrects a previously emitted invoice: that is a
 * rectificative concern (SettlementService::rectify), not a settlement.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InvalidSettlementAmountException extends RuntimeException
{
    public static function forAmount(int $amount): self
    {
        return new self(
            "Settlement amount [{$amount}] is invalid: the unconsumed value to liquidate ".
            'requires a strictly positive base-100 amount (e.g. 5000 for €50.00). '.
            'Zero is the "ninguno" artifact — emit no document at all; a negative amount '.
            'is a rectificative concern (SettlementService::rectify).'
        );
    }
}
