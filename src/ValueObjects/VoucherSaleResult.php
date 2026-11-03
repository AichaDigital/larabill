<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Larabill\Enums\VoucherSaleOutcome;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\VoucherSaleClaim;

/**
 * The result of `VoucherSaleService::sellVoucher()` (AID-1825): the named
 * outcome and, on SOLD only, the four rows of the sale — the issued, PAID
 * voucher-sale invoice, the payment that settles it, the voucher entry and
 * the claim. On any refusal every row is null and nothing was written.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final readonly class VoucherSaleResult
{
    public function __construct(
        public VoucherSaleOutcome $outcome,
        public ?Invoice $invoice = null,
        public ?GroupedPayment $payment = null,
        public ?CreditMovement $entry = null,
        public ?VoucherSaleClaim $claim = null,
    ) {}

    public static function refused(VoucherSaleOutcome $outcome): self
    {
        return new self($outcome);
    }
}
