<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Exceptions\NonProportionableLineException;
use AichaDigital\Larabill\Models\InvoiceItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Pure arithmetic for the proportional unconsumed-time base of an EMITTED
 * line (ADR-014 eje 2, AID-971).
 *
 * ADR-014 §3 eje 2: the unconsumed part is a proportion of WHAT WAS
 * ACTUALLY BILLED (ADR-013 doctrine) — the emitted line's own
 * `taxable_amount`, never the contract price, the live catalogue or an
 * `ArticlePrice`/`ArticleOverride` read. The service period comes from the
 * line's own `service_date_from` / `service_date_to`; larabill never
 * invents a period (AID-589 doctrine) and refuses one that is missing or
 * inverted.
 *
 * This calculator is the consumer's tool for DERIVING the amount it then
 * feeds to `SettlementService::settleUnconsumed()` (or `rectify()`): the
 * services never call it themselves and no I/O happens here — no DB reads,
 * no catalogue reads, no writes.
 *
 * Day counting is INCLUSIVE on both ends at day granularity:
 * `$consumedUntil` is the LAST consumed day (inclusive). An `$consumedUntil`
 * before `service_date_from` clamps to zero consumed days (full refund);
 * one at or after `service_date_to` means fully consumed (base 0).
 *
 * The result is rounded HALF-UP on the exact half with pure integer math:
 * `intdiv(2 * base * unconsumedDays + totalDays, 2 * totalDays)`, which is
 * floor(base × unconsumedDays / totalDays + 0.5). Integer math preserves
 * the base-100 invariant — no float representation drift between the billed
 * cents and the returned cents.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class SettlementArithmetic
{
    /**
     * The unconsumed taxable base of the emitted line, in base-100 unscaled
     * cents, rounded half-up.
     *
     * @param  InvoiceItem  $line  an EMITTED line: the base is its billed
     *                             `taxable_amount` (ADR-013)
     * @param  DateTimeInterface  $consumedUntil  the LAST consumed day (inclusive)
     * @return int base-100 unscaled unconsumed taxable base (e.g. 5000 = €50.00)
     */
    public function unconsumedBase(InvoiceItem $line, DateTimeInterface $consumedUntil): int
    {
        $from = $line->service_date_from;
        $to   = $line->service_date_to;

        if ($from === null || $to === null) {
            throw NonProportionableLineException::forMissingServicePeriod($line);
        }

        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->startOfDay();

        if ($to->lessThan($from)) {
            throw NonProportionableLineException::forInvertedPeriod($line);
        }

        $base = $line->taxable_amount->unscaledValue();

        if ($base < 0) {
            throw NonProportionableLineException::forNegativeBase($line);
        }

        if ($base === 0) {
            return 0; // Nothing to distribute.
        }

        $totalDays = (int) $from->diffInDays($to) + 1;

        $until = $consumedUntil instanceof CarbonInterface
            ? $consumedUntil->copy()->startOfDay()
            : Carbon::instance($consumedUntil)->startOfDay();

        $consumedDays   = max(0, min($totalDays, (int) $from->diffInDays($until) + 1));
        $unconsumedDays = $totalDays - $consumedDays;

        if ($unconsumedDays === 0) {
            return 0;
        }

        return intdiv(2 * $base * $unconsumedDays + $totalDays, 2 * $totalDays);
    }
}
