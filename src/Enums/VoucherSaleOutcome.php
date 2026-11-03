<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The outcome of `VoucherSaleService::sellVoucher()` (AID-1825 minimal scope
 * §1-§2). SOLD is the only outcome that wrote anything; every other outcome
 * is a named refusal with ZERO writes — the money it describes is not a sale
 * and its handling is administration's, outside larabill (operator 19:22).
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
enum VoucherSaleOutcome: string
{
    /** The voucher was sold (or the same key replayed a sale already made). */
    case Sold = 'sold';

    /** The amount received differs from the proforma total. */
    case AmountMismatch = 'amount_mismatch';

    /** The currency received differs from the proforma's. */
    case CurrencyMismatch = 'currency_mismatch';

    /** The payer is not the proforma's billable user. */
    case PayerMismatch = 'payer_mismatch';

    /** The proforma is not in force (cancelled, or not a DRAFT/SENT proforma). */
    case ProformaNotInForce = 'proforma_not_in_force';

    /** The proforma is already claimed by another key, converting or converted: a second payment for one sale. */
    case Duplicate = 'duplicate';

    public function isSold(): bool
    {
        return $this === self::Sold;
    }
}
