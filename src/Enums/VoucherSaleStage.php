<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The stage of a voucher sale on its claim (AID-1825 minimal scope §1):
 * PENDING from S1 (payment recorded, nothing spendable) until S3a opens;
 * ISSUING while the sale invoice is being numbered; BACKED once S3b committed
 * the collection and the voucher entry. `reverse()` of the funding payment
 * is allowed at PENDING only (§1a rule 5).
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
enum VoucherSaleStage: int
{
    case Pending = 0;
    case Issuing = 1;
    case Backed  = 2;
}
