<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\ValueObjects;

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\InvalidBillingIntervalException;
use AichaDigital\Larabill\Exceptions\LegacyCadenceUnsupportedException;
use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Billing interval value object — the ADR-015 cadence domain.
 *
 * Immutable representation of a billing cadence as quantity + unit, replacing
 * the closed `BillingFrequency` enum (ADR-015 §2.4). The cadence domain is
 * months 1-12 and years 1-10; weekly and biweekly cadences are rejected by
 * operator decision (zero instances in the migration corpus, AID-895 survey).
 *
 * Overflow semantics are PINNED to the current engine: addition overflows
 * (`addMonths`/`addYears` — Jan 31 + 1 month → Mar 3, exactly as
 * `BillingFrequency::addToDate()` behaves today) while subtraction uses
 * NoOverflow (`subMonthsNoOverflow`/`subYearsNoOverflow` — Jan 31 − 1 month →
 * Jan 30). Changing addition semantics would silently shift existing
 * schedules on migration; it is out of scope (spec §2.4).
 *
 * `approximateDays()` is an approximation for window math only — never for
 * scheduling, which always goes through `addToDate()`.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
final class BillingInterval
{
    public function __construct(
        public readonly string $unit,
        public readonly int $quantity,
    ) {
        if ($unit !== 'month' && $unit !== 'year') {
            throw InvalidBillingIntervalException::forUnit($unit);
        }

        $maxQuantity = $unit === 'month' ? 12 : 10;

        if ($quantity < 1 || $quantity > $maxQuantity) {
            throw InvalidBillingIntervalException::forQuantity($quantity, $unit);
        }
    }

    /**
     * Add this interval to a date, with the engine's overflow semantics
     * (Jan 31 + 1 month → Mar 3). Returns a fresh Carbon; never mutates the
     * input.
     */
    public function addToDate(DateTimeInterface $date): Carbon
    {
        $carbon = $this->toCarbon($date);

        if ($this->unit === 'month') {
            return $carbon->addMonths($this->quantity);
        }

        return $carbon->addYears($this->quantity);
    }

    /**
     * Subtract this interval from a date, with NoOverflow semantics
     * (Jan 31 − 1 month → Jan 30, never Mar 2/3). Returns a fresh Carbon;
     * never mutates the input.
     */
    public function subtractFromDate(DateTimeInterface $date): Carbon
    {
        $carbon = $this->toCarbon($date);

        if ($this->unit === 'month') {
            return $carbon->subMonthsNoOverflow($this->quantity);
        }

        return $carbon->subYearsNoOverflow($this->quantity);
    }

    /**
     * Approximate length in days for window math only — never for scheduling
     * (scheduling always uses `addToDate()`).
     */
    public function approximateDays(): int
    {
        if ($this->unit === 'month') {
            return $this->quantity * 30;
        }

        return $this->quantity * 365;
    }

    /**
     * Human-readable label: "1 month", "3 months", "1 year", "5 years".
     */
    public function label(): string
    {
        $noun = $this->unit === 'month' ? 'month' : 'year';

        return sprintf('%d %s%s', $this->quantity, $noun, $this->quantity === 1 ? '' : 's');
    }

    /**
     * Two intervals are equal when both unit and quantity are identical.
     */
    public function equals(self $other): bool
    {
        return $this->unit     === $other->unit
            && $this->quantity === $other->quantity;
    }

    /**
     * Deterministic bridge from the retired `BillingFrequency` enum to the new
     * cadence domain (ADR-015 §4.1), for later migration slices.
     *
     * Legacy integer values (`BillingFrequency` case values): `ONE_TIME` (0) →
     * null (null cadence is a real state); `MONTHLY` (3) → month/1,
     * `BIMONTHLY` (4) → month/2, `QUARTERLY` (5) → month/3, `SEMIANNUAL` (6) →
     * month/6, `YEARLY` (7) → year/1, `BIENNIAL` (8) → year/2, `TRIENNIAL` (9)
     * → year/3. `WEEKLY` (1) and `BIWEEKLY` (2) throw
     * `LegacyCadenceUnsupportedException` — the new domain cannot represent
     * them; this is the ONE qualified narrowing of STABILITY.md §3 (zero
     * corpus instances, AID-895 survey; the §4.3 preflight loud-aborts any
     * weekly/biweekly row, so no 7.0 installation can hold such data) — and
     * any value outside the legacy domain 0-9 throws the plain
     * `InvalidBillingIntervalException`.
     *
     * @throws LegacyCadenceUnsupportedException for `WEEKLY`/`BIWEEKLY` —
     *                                           the qualified STABILITY.md §3 narrowing
     * @throws InvalidBillingIntervalException for out-of-domain values
     */
    public static function fromLegacyFrequency(int $frequency): ?self
    {
        return match ($frequency) {
            BillingFrequency::ONE_TIME->value   => null,
            BillingFrequency::MONTHLY->value    => new self('month', 1),
            BillingFrequency::BIMONTHLY->value  => new self('month', 2),
            BillingFrequency::QUARTERLY->value  => new self('month', 3),
            BillingFrequency::SEMIANNUAL->value => new self('month', 6),
            BillingFrequency::YEARLY->value     => new self('year', 1),
            BillingFrequency::BIENNIAL->value   => new self('year', 2),
            BillingFrequency::TRIENNIAL->value  => new self('year', 3),
            BillingFrequency::WEEKLY->value,
            BillingFrequency::BIWEEKLY->value   => throw LegacyCadenceUnsupportedException::forLegacyFrequency($frequency),
            default                             => throw InvalidBillingIntervalException::forLegacyFrequency($frequency),
        };
    }

    /**
     * Reverse bridge to the retired `BillingFrequency` enum — the mirror of
     * `fromLegacyFrequency()`, keeping the enum the single source of both
     * directions while transitional `BillingFrequency`-typed read methods
     * still exist (ADR-015 §5; the shim retirement was deferred to 9.0 by ADR-015 Enmienda 2 and the `BillingFrequency` family goes in 10.0 — this bridge is `@deprecated since 9.0.0, removal in 10.0`).
     *
     * Only the seven legacy-representable cadences map back. A cadence inside
     * the new domain but outside the legacy image (e.g. month/5, year/4) has
     * NO legacy case: this bridge throws rather than silently dropping the
     * cadence from a read — the caller must migrate off the enum bridge.
     *
     * @throws InvalidArgumentException when no legacy case represents this
     *                                  cadence (or for a NULL cadence — the
     *                                  caller decides ONE_TIME itself).
     */
    public function toLegacyFrequency(): BillingFrequency
    {
        return match ([$this->unit, $this->quantity]) {
            ['month', 1] => BillingFrequency::MONTHLY,
            ['month', 2] => BillingFrequency::BIMONTHLY,
            ['month', 3] => BillingFrequency::QUARTERLY,
            ['month', 6] => BillingFrequency::SEMIANNUAL,
            ['year', 1]  => BillingFrequency::YEARLY,
            ['year', 2]  => BillingFrequency::BIENNIAL,
            ['year', 3]  => BillingFrequency::TRIENNIAL,
            default      => throw new InvalidArgumentException(sprintf(
                "Billing interval '%s' has no legacy BillingFrequency equivalent, so the transitional "
                .'enum bridge cannot represent it. Remediation: read the cadence as a BillingInterval '
                .'(billing_unit/billing_quantity) instead of converting back to the retired enum.',
                $this->label(),
            )),
        };
    }

    /**
     * Copy the input into a fresh Carbon instance — never mutate the caller's
     * date.
     */
    private function toCarbon(DateTimeInterface $date): Carbon
    {
        return $date instanceof Carbon
            ? $date->copy()
            : Carbon::instance($date);
    }
}
