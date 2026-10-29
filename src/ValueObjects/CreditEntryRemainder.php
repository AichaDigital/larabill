<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;

/**
 * One live credit entry of a CreditBreakdown (AID-1817): the entry movement,
 * what is left of it after its active exits, and the advance invoice that
 * documents it (null for a consumable entry).
 *
 * @api Supported public surface (AID-1817; see docs/api-surface.md).
 */
final readonly class CreditEntryRemainder
{
    public function __construct(
        public string $movementId,
        public FixedDecimal $remaining,
        public ?string $advanceInvoiceId,
    ) {}

    /** True when the entry is linked to an advance invoice: not spendable with apply(). */
    public function isAdvanceBacked(): bool
    {
        return $this->advanceInvoiceId !== null;
    }
}
