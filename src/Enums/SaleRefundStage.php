<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The stage of a durable refund intent (AID-1997 spec §4.5, D8 rev11).
 *
 * The intent is born `Intended` (reserving capacity under the reservation
 * lock), flips to `Refunded` as the refund's LAST write after post-commit
 * detection passes (R3(c)), or is moved to `Abandoned` by the same key's
 * owner-authorized recovery call for a proven pre-issuance refusal (R3-7) —
 * the only non-terminal state from which capacity is released. Both
 * `Refunded` and `Abandoned` are terminal; no intent row is ever deleted.
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
enum SaleRefundStage: int
{
    case Intended  = 0;
    case Refunded  = 1;
    case Abandoned = 2;
}
