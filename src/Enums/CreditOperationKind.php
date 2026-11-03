<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The kind of a keyed ledger operation (AID-1825): what a `credit_operations`
 * row records so the same key replays it.
 *
 * @api Supported public surface (AID-1825; see docs/api-surface.md).
 */
enum CreditOperationKind: int
{
    /** A voucher consumption: CONSUMED exits against voucher-backed entries. */
    case Consumption = 0;
}
