<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use Carbon\CarbonInterface;

/**
 * The data of ONE consumption receipt (AID-1825 minimal scope §1 item 5),
 * one per CONSUMED exit: the voucher-sale invoice the balance came from,
 * what was consumed, what is left on that entry and on the customer's
 * voucher balance after it. The consumer renders it in its own non-fiscal
 * series with the mention that it is NOT an invoice; it never enters a
 * fiscal series.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final readonly class VoucherReceiptData
{
    public function __construct(
        public string $exitId,
        public string $saleInvoiceId,
        public ?string $saleInvoiceNumber,
        public ?CarbonInterface $saleInvoiceDate,
        public FixedDecimal $consumedAmount,
        public FixedDecimal $remainingOnEntry,
        public FixedDecimal $remainingVoucherBalance,
        public string $serviceRef,
        public ?string $serviceDescription,
        public CarbonInterface $consumedAt,
    ) {}
}
