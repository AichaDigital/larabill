<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Exceptions;

use RuntimeException;

/**
 * A proforma already carries a live (non-Released) one-off reservation with
 * another key (AID-1997 spec §4.1 step 4): one live reservation per proforma,
 * enforced under the proforma lock. The released history never blocks a new
 * reservation — the lookup is over LIVE reservations, never `.first()` over
 * history (gate round 1, S1).
 *
 * @api Supported public surface (AID-1997; see docs/api-surface.md).
 */
final class SaleReservedException extends RuntimeException
{
    public static function reservedProforma(string $proformaId, string $reservationId, string $operation): self
    {
        return new self(
            "Proforma [{$proformaId}] carries a live one-off reservation [{$reservationId}]; ".
            "the reservation's own flow is the only writer (refused: {$operation})."
        );
    }

    public static function forProforma(string $proformaId, string $liveKey, string $attemptedKey): self
    {
        return new self(
            "Proforma [{$proformaId}] already carries a live one-off reservation [{$liveKey}]; ".
            "release it before reserving with [{$attemptedKey}]."
        );
    }
}
