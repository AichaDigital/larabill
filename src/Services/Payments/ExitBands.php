<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services\Payments;

use AichaDigital\Larabill\Exceptions\InvalidAdvanceInvoiceException;
use AichaDigital\Larabill\Models\CreditMovement;
use LogicException;

/**
 * The cent-tape placement of a credit exit (AID-1778, spec §5.3–§5.4).
 *
 * An exit consumes a contiguous band `[band_start, band_start + abs(amount))`
 * of the entry it consumes; the band's END is derived by arithmetic, never
 * stored. Placement happens at CREATION, under the source-payment lock the
 * creating transaction already holds — no new lock: both exit creators
 * (`CustomerCredit::recordExit()` and `PaymentService::consumeEntries()`)
 * serialize on the source payment row of the entry they consume, so the
 * computed free tape cannot go stale before the INSERT commits.
 *
 * Assignment rule: the free tape is `[0, G)` minus the bands of the entry's
 * ACTIVE exits; the take fills the gaps LOWEST-GAP-FIRST and each filled run
 * becomes ONE exit row with `band_start` = the gap's start. A take larger
 * than every single free gap is SPLIT — one row per gap, all consuming the
 * same entry (the row-level premise holds for every resulting row: one entry,
 * one amount, one contiguous band).
 *
 * Reversal freeing is a READ-SIDE fact (spec §5.4): a reversed exit keeps its
 * stored band on the row (audit history) but leaves the tape — only ACTIVE
 * rows are read here — so its band returns to the free tape with no write and
 * NO recomputation of any surviving exit's band. Refilling a freed hole beats
 * the frontier: a frontier-continuation implementation would price the same
 * cent ranges twice and break the telescoping identity Σ f(b_i) − f(a_i) = T.
 *
 * On an UNRESOLVABLE entry (an active exit already carrying a NULL band — the
 * legacy backfill's declaration, spec §5.7) the placement propagates NULL:
 * the caller writes `band_start = NULL` on the new exit too and does NOT
 * split — no bands, no split to invent, no quiet re-anchor. The ledger
 * arithmetic never reads the column and keeps working untouched.
 *
 * @internal Not part of the supported surface; called only by the two exit creators.
 */
final class ExitBands
{
    /**
     * The cent segments of the entry's tape that a take of $takeCents fills,
     * in ascending position order.
     *
     * @param  CreditMovement  $entry  The consumed ENTRY (positive amount)
     * @param  int  $takeCents  The take, in base-100 cents (positive)
     * @return list<array{0: int, 1: int}>|null The [start, length] segments
     *                                          (length > 0 each, Σ lengths = $takeCents), or NULL when the
     *                                          entry is unresolvable and the caller must write NULL bands.
     *
     * @throws InvalidAdvanceInvoiceException (cannotPlaceExit) when the free
     *                                                          tape totals less than the take — broken ledger arithmetic
     *                                                          (unreachable under a consistent ledger, where the free tape is
     *                                                          always at least the entry's remaining balance); loud, before
     *                                                          any write.
     */
    public static function segmentsFor(CreditMovement $entry, int $takeCents): ?array
    {
        $gross = $entry->amount->toScale(2)->unscaledValue();

        if (! $entry->isEntry() || $gross <= 0) {
            throw new LogicException('ExitBands::segmentsFor() places a take on an ENTRY\'s cent tape; an exit has no tape of its own.');
        }

        if ($takeCents <= 0) {
            throw new LogicException('ExitBands::segmentsFor() requires a positive take in base-100 cents.');
        }

        $exits = CreditMovement::query()
            ->where('consumes_movement_id', $entry->getKey())
            ->active()
            ->get(['band_start', 'amount']);

        if ($exits->contains(static fn (CreditMovement $exit): bool => $exit->band_start === null)) {
            return null; // unresolvable entry: propagate NULL (spec §5.7)
        }

        $bands = [];
        foreach ($exits as $exit) {
            $start   = (int) $exit->band_start;
            $bands[] = [$start, $start + abs($exit->amount->toScale(2)->unscaledValue())];
        }
        usort($bands, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $gaps   = [];
        $free   = 0;
        $cursor = 0;
        foreach ($bands as [$start, $end]) {
            if ($start > $cursor) {
                $gaps[] = [$cursor, $start];
                $free   += $start - $cursor;
            }

            $cursor = max($cursor, $end);
        }

        if ($cursor < $gross) {
            $gaps[] = [$cursor, $gross];
            $free   += $gross - $cursor;
        }

        if ($free < $takeCents) {
            throw InvalidAdvanceInvoiceException::cannotPlaceExit((string) $entry->getKey(), $takeCents, $free);
        }

        $segments = [];
        $left     = $takeCents;
        foreach ($gaps as [$start, $end]) {
            if ($left <= 0) {
                break;
            }

            $length = min($end - $start, $left);
            if ($length <= 0) {
                continue;
            }

            $segments[] = [$start, $length];
            $left       -= $length;
        }

        return $segments;
    }
}
