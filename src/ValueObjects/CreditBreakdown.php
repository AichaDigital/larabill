<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;

/**
 * The live credit balance of a customer in one currency, split by the origin
 * of each entry (AID-1817), as returned by CustomerCredit::breakdown():
 *
 * - `consumable` — entries with no advance invoice; spendable as a payment
 *   with CustomerCredit::apply().
 * - `advanceBacked` — entries linked to an advance invoice (whatever the
 *   advance's document state). Their VAT was already declared on the
 *   advance, so they must not be applied as a payment; they are consumed as
 *   a deduction line (AID-1815).
 *
 * consumable + advanceBacked equals CustomerCredit::balance(). `entries`
 * lists the entries with a positive remainder, oldest first (`occurred_at`,
 * then `id`, the FIFO order of apply() and refund()).
 *
 * @api Supported public surface (AID-1817; see docs/api-surface.md).
 */
final readonly class CreditBreakdown
{
    /**
     * @param  list<CreditEntryRemainder>  $entries
     */
    public function __construct(
        public FixedDecimal $consumable,
        public FixedDecimal $advanceBacked,
        public array $entries,
    ) {}

    /** consumable + advanceBacked: the bound balance. */
    public function total(): FixedDecimal
    {
        return $this->consumable->plus($this->advanceBacked)->toScale(2);
    }
}
