<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * The stage of a one-off sale reservation (AID-1997 spec §4, D2).
 *
 * The reservation walks Reserved → Paid → Issuing → Settled; Released is the
 * abandoned-checkout terminal (the key is spent, the proforma reopens). A
 * settled reservation is a replay (D7) and a permanent provenance marker
 * (D6): provenance protection never lifts once a charge was recorded.
 *
 * @api Supported public surface (AID-1997 spec §3; see docs/api-surface.md).
 */
enum SaleReservationStage: int
{
    case Reserved = 0;
    case Paid     = 1;
    case Issuing  = 2;
    case Settled  = 3;
    case Released = 4;

    /** Whether the stage is one the sale still moves forward from. */
    public function isTerminal(): bool
    {
        return $this === self::Settled || $this === self::Released;
    }
}
