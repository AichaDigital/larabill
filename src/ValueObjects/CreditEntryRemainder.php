<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;

/**
 * One live credit entry of a CreditBreakdown (AID-1817): the entry movement,
 * what is left of it after its active exits, the advance invoice that
 * documents it (null for a consumable entry) and, since AID-1825, the
 * voucher-sale invoice that backs it (null unless it is a voucher).
 *
 * @api Supported public surface (AID-1817; see docs/api-surface.md).
 */
final readonly class CreditEntryRemainder
{
    public function __construct(
        public string $movementId,
        public FixedDecimal $remaining,
        public ?string $advanceInvoiceId,
        public ?string $saleInvoiceId = null,
    ) {}

    /** True when the entry is linked to an advance invoice: not spendable with apply(). */
    public function isAdvanceBacked(): bool
    {
        return $this->advanceInvoiceId !== null;
    }

    /** True when the entry is backed by a voucher-sale invoice (AID-1825): consumed against services, never a payment. */
    public function isVoucherBacked(): bool
    {
        return $this->saleInvoiceId !== null;
    }
}
