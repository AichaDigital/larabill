<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Larabill\Models\CreditMovement;
use Illuminate\Database\Eloquent\Collection;

/**
 * The result of `VoucherSaleService::consumeVoucher()` (AID-1825): the
 * CONSUMED exits written (one per voucher entry taken, FIFO) and one receipt
 * per exit. A replay returns the exits of the original operation.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final readonly class VoucherConsumptionResult
{
    /**
     * @param  Collection<int, CreditMovement>  $exits
     * @param  list<VoucherReceiptData>  $receipts
     */
    public function __construct(
        public Collection $exits,
        public array $receipts,
        public bool $replayed,
    ) {}
}
