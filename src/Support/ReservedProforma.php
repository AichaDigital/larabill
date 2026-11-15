<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Exceptions\SaleReservedException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\SaleReservation;

/**
 * The one-off reservation guard (AID-1997 spec §5 G5/G13-G15): a proforma
 * carrying a LIVE (non-Released) reservation belongs to that reservation —
 * every other writer that could take the proforma away from the sale
 * refuses while one exists, unless it runs inside the reservation's own
 * authorization (SaleReservation::withReservationAuthorization, §4.1).
 *
 * Deliberately NOT a guard on the reservation flow itself: the lookup is a
 * plain read under the caller's own locks (each entry point locks the
 * proforma row first — §4.0's serialization point).
 *
 * @internal OneOffSaleService and the guarded entry points are the only callers.
 */
final class ReservedProforma
{
    /**
     * Refuse $operation on $proforma when a LIVE reservation claims it.
     *
     * @param  string  $operation  the refusing entry point, for the message
     *
     * @throws SaleReservedException
     */
    public static function assertNotReserved(Invoice $proforma, string $operation): void
    {
        $reservationId = $proforma->getAttribute('sale_reservation_id');

        // The reservation's own flow (reserve/release/settle) is the authorized
        // writer of its proforma (spec §4.1 step 6, §4.3): its runs inside
        // SaleReservation::withReservationAuthorization().
        if ($reservationId !== null && SaleReservation::isAuthorizedFor((string) $reservationId)) {
            return;
        }

        if ($reservationId !== null) {
            throw SaleReservedException::reservedProforma((string) $proforma->getKey(), (string) $reservationId, $operation);
        }

        // The invoice-side link is the primary marker, but a lost link must
        // still refuse (the claim guards' dual-read doctrine, AID-1825):
        // a LIVE reservation naming this proforma is authoritative — unless
        // that live reservation is the authorized writer right now.
        $live = SaleReservation::query()
            ->where('proforma_id', $proforma->getKey())
            ->where('stage', '!=', SaleReservationStage::Released->value)
            ->get();

        foreach ($live as $reservation) {
            if (SaleReservation::isAuthorizedFor((string) $reservation->id)) {
                return; // the authorized reservation is the writer right now
            }
        }

        if ($live->isNotEmpty()) {
            throw SaleReservedException::reservedProforma((string) $proforma->getKey(), (string) $live->first()->id, $operation);
        }
    }
}
