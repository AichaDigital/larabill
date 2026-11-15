<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DTO;

use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\SaleReservation;

/**
 * The result of a one-off sale settlement (AID-1997 spec §3): the whole
 * evidence tuple — reservation, payment, the sale invoice and its single
 * allocation. A Settled replay returns the SAME tuple (D7: the evidence,
 * not today's state).
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
final readonly class OneOffSaleResult
{
    public function __construct(
        public SaleReservation $reservation,
        public GroupedPayment $payment,
        public Invoice $invoice,
        public PaymentAllocation $allocation,
    ) {}
}
