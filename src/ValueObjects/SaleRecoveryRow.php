<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Larabill\Enums\SaleIntegrityReason;
use AichaDigital\Larabill\Enums\VoucherSaleStage;

/**
 * One row of the voucher-sale recovery reads (AID-1825 minimal scope §3):
 * what identifies the sale — the invoice, its proforma, its payment and the
 * stage of its claim — and, for `salesIncoherent()`, the reason it is listed.
 * `paymentId`, `stage` and `proformaId` are null when the claim is missing
 * (the invoice is then the only thing left to start from).
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
final readonly class SaleRecoveryRow
{
    public function __construct(
        public string $invoiceId,
        public ?string $proformaId,
        public ?string $paymentId,
        public ?string $claimId,
        public ?VoucherSaleStage $stage,
        public ?SaleIntegrityReason $reason,
    ) {}
}
