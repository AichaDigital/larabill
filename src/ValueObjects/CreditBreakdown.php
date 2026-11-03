<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;

/**
 * The live credit balance of a customer in one currency, split by the origin
 * of each entry (AID-1817, AID-1825), as returned by CustomerCredit::breakdown():
 *
 * - `consumable` — entries with no backing; spendable as a payment with
 *   CustomerCredit::apply().
 * - `advanceBacked` — entries linked to an advance invoice (whatever the
 *   advance's document state). Their VAT was already declared on the
 *   advance, so they must not be applied as a payment; they are consumed as
 *   a deduction line (AID-1815).
 * - `voucherBacked` — entries born of a voucher sale (`sale_invoice_id`,
 *   AID-1825): balance contracted to consume services. Never a payment,
 *   never refunded in this version; consumed with consumeVoucher().
 *
 * consumable + advanceBacked + voucherBacked equals CustomerCredit::balance().
 * `entries` lists the entries with a positive remainder, oldest first
 * (`occurred_at`, then `id`, the FIFO order of apply() and refund()).
 *
 * @api Supported public surface (AID-1817; see docs/api-surface.md).
 */
final readonly class CreditBreakdown
{
    /** AID-1825: the voucher-backed part; zero on a database without vouchers. */
    public FixedDecimal $voucherBacked;

    /**
     * @param  list<CreditEntryRemainder>  $entries
     * @param  FixedDecimal|null  $voucherBacked  null reads as zero, so 9.1 callers keep their three-argument shape
     */
    public function __construct(
        public FixedDecimal $consumable,
        public FixedDecimal $advanceBacked,
        public array $entries,
        ?FixedDecimal $voucherBacked = null,
    ) {
        $this->voucherBacked = $voucherBacked ?? FixedDecimal::zero(2);
    }

    /** consumable + advanceBacked + voucherBacked: the bound balance. */
    public function total(): FixedDecimal
    {
        return $this->consumable->plus($this->advanceBacked)->plus($this->voucherBacked)->toScale(2);
    }
}
