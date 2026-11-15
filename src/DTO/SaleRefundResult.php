<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\DTO;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\SaleRefund;

/**
 * The result of a one-off sale refund (AID-1997 spec §3, D8 rev11): the
 * intent row, the refund-owned rectificative (null only on the terminal
 * replay of an `Abandoned` intent, which owns no document), and the
 * remaining refundable after it.
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
final readonly class SaleRefundResult
{
    public function __construct(
        public SaleRefund $refund,
        public ?Invoice $rectificative,
        public FixedDecimal $remainingRefundable,
    ) {}
}
