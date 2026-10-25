<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * The customer credit cannot cover the requested amount, the currency of the
 * credit to apply cannot be decided, or an entry cannot be reversed (base-100).
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class InsufficientCreditException extends RuntimeException
{
    public static function forBalance(string $billableUserId, string $currency, int $balanceUnscaled, int $requestedUnscaled): self
    {
        return new self("User {$billableUserId} has insufficient {$currency} credit: requested {$requestedUnscaled}, available {$balanceUnscaled} (base-100).");
    }

    /**
     * apply() on an invoice with no fiscal currency, for a customer holding
     * no positive credit balance in any currency (spec §13.10 row 42).
     */
    public static function noCreditForCustomer(string $billableUserId): self
    {
        return new self("User {$billableUserId} holds no credit in any currency.");
    }

    /**
     * apply() on an invoice with no fiscal currency, for a customer holding a
     * positive credit balance in several currencies: the credit to apply is
     * ambiguous (spec §13.10 row 42).
     *
     * @param  list<string>  $currencies
     */
    public static function currencyAmbiguous(string $billableUserId, array $currencies): self
    {
        return new self("User {$billableUserId} holds credit in ".implode(', ', $currencies).' and the invoice has no fiscal currency: the credit to apply is ambiguous.');
    }

    public static function entryHasActiveExits(string $paymentId, string $movementId): self
    {
        return new self("Payment {$paymentId} cannot be voided: its credit entry {$movementId} has already been consumed by active exits.");
    }
}
